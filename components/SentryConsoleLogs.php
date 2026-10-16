<?php

namespace giantbits\crelish\components;

use Sentry;
use yii\base\ActionEvent;
use yii\base\Event;
use yii\console\Application as ConsoleApplication;
use yii\queue\cli\Command as QueueCommand;
use yii\queue\Queue;

/**
 * Sends a record of every console command run to Sentry Logs: one entry when the command
 * ends (route, exit code, duration, memory) plus what the command logged with Yii::info,
 * Yii::warning or Yii::error while it ran. In Sentry, "command:job-feed/fetch" then lists
 * each run of that command. Logs are not metered per monitor like Sentry Crons are.
 *
 * Switched on with params['crelish']['sentry_logs'] = true, on applications that have the
 * Sentry component (the projects only add it on the server).
 *
 * A queue run that found no job sends nothing: cron starts queue/run every minute, and
 * logging each idle run would be most of the volume and none of the information.
 * Framework messages (categories yii\*) are left out, and so is output a command only
 * echoes to the console.
 */
final class SentryConsoleLogs
{
  /** messages kept per run; a runaway loop must not fill the memory or the log quota */
  private const MAX_MESSAGES = 200;

  private ?string $route = null;
  private bool $queueRun = false;
  private float $startedAt = 0.0;
  private int $jobs = 0;

  /** @var array<int, array{0: string, 1: int, 2: string}> text, Yii level, category */
  private array $messages = [];

  private function __construct(
    private readonly ConsoleApplication $app,
    private readonly string $sentryComponent
  ) {
  }

  public static function attach(ConsoleApplication $app, string $sentryComponent = 'crelishSentry'): ?self
  {
    if (empty($app->params['crelish']['sentry_logs']) || !$app->has($sentryComponent)) {
      return null;
    }

    $logs = new self($app, $sentryComponent);
    $app->on(ConsoleApplication::EVENT_BEFORE_ACTION, [$logs, 'start']);
    $app->on(ConsoleApplication::EVENT_AFTER_ACTION, [$logs, 'finish']);

    if (class_exists(Queue::class)) {
      Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, [$logs, 'countJob']);
      Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, [$logs, 'countJob']);
    }

    $app->getLog()->targets['sentryConsoleLogs'] = new SentryConsoleLogTarget(['logs' => $logs]);

    // A command that throws never reaches "after action"
    register_shutdown_function([$logs, 'finishUnfinished']);

    return $logs;
  }

  public function start(ActionEvent $event): void
  {
    if ($this->route !== null || !$this->sentryReady()) {
      return;
    }

    // What was logged while the application booted ("Sentry initialized") is not the run's
    $this->app->getLog()->getLogger()->flush();

    $this->route = $event->action->getUniqueId();
    $this->queueRun = $event->action->controller instanceof QueueCommand;
    $this->startedAt = microtime(true);
    $this->jobs = 0;
    $this->messages = [];
  }

  public function finish(ActionEvent $event): void
  {
    if ($this->route === null || $event->action->getUniqueId() !== $this->route) {
      return;
    }

    $exitCode = is_int($event->result) ? $event->result : 0;
    $this->send($exitCode, null);
  }

  public function finishUnfinished(): void
  {
    if ($this->route === null) {
      return;
    }

    $error = error_get_last();
    $this->send(1, $error['message'] ?? 'the command ended with an uncaught exception');
  }

  public function countJob(): void
  {
    $this->jobs++;
  }

  /**
   * A message Yii logged during the run (from SentryConsoleLogTarget).
   */
  public function collect(string $text, int $level, string $category): void
  {
    if ($this->route !== null && count($this->messages) < self::MAX_MESSAGES) {
      $this->messages[] = [$text, $level, $category];
    }
  }

  private function send(int $exitCode, ?string $failure): void
  {
    // Yii hands buffered messages to the targets on flush; they belong to this run
    $this->app->getLog()->getLogger()->flush();

    $route = $this->route;
    $this->route = null;
    $messages = $this->messages;
    $this->messages = [];

    if ($this->queueRun && $this->jobs === 0 && $exitCode === 0 && $failure === null && $messages === []) {
      return;
    }

    try {
      $logger = Sentry\logger();
      $attributes = ['command' => $route];

      foreach ($messages as [$text, $level, $category]) {
        $method = match ($level) {
          \yii\log\Logger::LEVEL_ERROR => 'error',
          \yii\log\Logger::LEVEL_WARNING => 'warn',
          default => 'info',
        };
        $logger->$method($text, [], $attributes + ['category' => $category]);
      }

      $summary = $attributes + [
        'exit_code' => $exitCode,
        'duration_ms' => (int)round((microtime(true) - $this->startedAt) * 1000),
        'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
      ];
      if ($this->queueRun) {
        $summary['jobs'] = $this->jobs;
      }

      if ($failure !== null) {
        $logger->error('%s failed: %s', [$route, $failure], $summary);
      } elseif ($exitCode !== 0) {
        $logger->error('%s ended with exit code %d', [$route, $exitCode], $summary);
      } else {
        $logger->info('%s finished', [$route], $summary);
      }

      $logger->flush();
    } catch (\Throwable $e) {
      // Logging must never break the command itself.
    }
  }

  private function sentryReady(): bool
  {
    $sentry = $this->app->get($this->sentryComponent, false);
    return $sentry instanceof CrelishSentryComponent && $sentry->isInitialized();
  }
}
