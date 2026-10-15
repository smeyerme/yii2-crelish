<?php

namespace giantbits\crelish\components;

use Sentry;
use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use yii\base\ActionEvent;
use yii\console\Application as ConsoleApplication;

/**
 * Reports cron commands to Sentry Crons, so a job that stops running, runs too long or
 * fails raises a Sentry issue.
 *
 * A project lists its cron commands in params:
 *
 *   'crelish' => ['sentry_cron_monitors' => [
 *     'queue/run'     => ['schedule' => '* * * * *', 'checkinMargin' => 5, 'maxRuntime' => 10],
 *     'access/remind' => ['schedule' => '0 6 * * *'],
 *   ]],
 *
 * Each run sends "in progress" when the command starts and "ok" (exit code 0) or
 * "error" when it ends; a command that dies with an exception reports "error" on
 * shutdown. The first check-in carries the schedule, so Sentry creates and updates the
 * monitor itself. The slug is "<app id>-<route>" unless 'slug' is given; the app id can
 * be overridden with params['crelish']['sentry_cron_prefix'].
 */
final class SentryCronMonitor
{
  private ?string $slug = null;
  private ?string $route = null;
  private ?MonitorConfig $config = null;
  private ?string $checkInId = null;
  private float $startedAt = 0.0;

  private function __construct(
    private readonly ConsoleApplication $app,
    private readonly array $monitors,
    private readonly string $prefix,
    private readonly string $sentryComponent
  ) {
  }

  public static function attach(ConsoleApplication $app, string $sentryComponent = 'crelishSentry'): ?self
  {
    $monitors = $app->params['crelish']['sentry_cron_monitors'] ?? [];
    if (empty($monitors) || !$app->has($sentryComponent)) {
      return null;
    }

    $monitor = new self($app, $monitors, (string)($app->params['crelish']['sentry_cron_prefix'] ?? $app->id), $sentryComponent);
    $app->on(ConsoleApplication::EVENT_BEFORE_ACTION, [$monitor, 'start']);
    $app->on(ConsoleApplication::EVENT_AFTER_ACTION, [$monitor, 'finish']);
    register_shutdown_function([$monitor, 'failIfUnfinished']);
    return $monitor;
  }

  public function start(ActionEvent $event): void
  {
    $route = $event->action->getUniqueId();
    $settings = $this->monitors[$route] ?? null;
    if ($settings === null || $this->slug !== null || !$this->sentryReady()) {
      return;
    }

    try {
      $this->slug = $settings['slug'] ?? $this->slugFor($route);
      $this->route = $route;
      $this->config = new MonitorConfig(
        MonitorSchedule::crontab($settings['schedule']),
        $settings['checkinMargin'] ?? 5,
        $settings['maxRuntime'] ?? 30,
        $settings['timezone'] ?? date_default_timezone_get(),
        $settings['failureIssueThreshold'] ?? 1,
        $settings['recoveryThreshold'] ?? 1
      );
      $this->startedAt = microtime(true);
      $this->checkInId = Sentry\captureCheckIn($this->slug, CheckInStatus::inProgress(), null, $this->config);
    } catch (\Throwable $e) {
      // Monitoring must never stop the job itself.
      $this->reset();
    }
  }

  public function finish(ActionEvent $event): void
  {
    if ($this->slug === null || $event->action->getUniqueId() !== $this->route) {
      return;
    }

    $failed = is_int($event->result) && $event->result !== 0;
    $this->send($failed ? CheckInStatus::error() : CheckInStatus::ok());
  }

  /**
   * A command that throws never reaches "after action"; its open check-in is closed as
   * failed when PHP shuts down.
   */
  public function failIfUnfinished(): void
  {
    if ($this->slug !== null) {
      $this->send(CheckInStatus::error());
    }
  }

  private function send(CheckInStatus $status): void
  {
    try {
      Sentry\captureCheckIn($this->slug, $status, microtime(true) - $this->startedAt, $this->config, $this->checkInId);
    } catch (\Throwable $e) {
    }
    $this->reset();
  }

  private function reset(): void
  {
    $this->slug = $this->route = $this->config = $this->checkInId = null;
  }

  private function slugFor(string $route): string
  {
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($this->prefix . '-' . $route)), '-');
  }

  private function sentryReady(): bool
  {
    $sentry = $this->app->get($this->sentryComponent, false);
    return $sentry instanceof CrelishSentryComponent && $sentry->isInitialized();
  }
}
