<?php

namespace giantbits\crelish\components;

use Sentry;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Yii;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\Event;
use yii\log\Logger;
use yii\queue\ExecEvent;
use yii\queue\PushEvent;
use yii\queue\Queue;

/**
 * Sends performance traces of chosen requests and queue jobs to Sentry.
 *
 * A project names what to trace, as route or job class patterns with a sample rate:
 *
 *   'crelish' => ['sentry_traces' => [
 *     'checkout/*' => 1.0,      // every request to the checkout controller
 *     'app\jobs\*' => 1.0,      // every queue job in app\jobs
 *   ]],
 *
 * Nothing else is traced, so ordinary page views cost no Sentry quota. A traced request
 * or job becomes one transaction with its database queries as spans; project code adds
 * its own spans with SentryTracing::span(). A job that uses CarriesSentryTrace continues
 * the trace of the request that queued it, so request and job show up as one trace.
 */
final class SentryTracing
{
  private ?Transaction $transaction = null;
  private float $startedAt = 0.0;

  /** @var array<string, float[]> open database profile blocks, by category and SQL */
  private array $openQueries = [];

  private function __construct(
    private readonly Application $app,
    private readonly array $rates,
    private readonly string $sentryComponent
  ) {
  }

  public static function attach(Application $app, string $sentryComponent = 'crelishSentry'): ?self
  {
    $rates = $app->params['crelish']['sentry_traces'] ?? [];
    if (empty($rates) || !$app->has($sentryComponent)) {
      return null;
    }

    $tracing = new self($app, $rates, $sentryComponent);

    if ($app instanceof \yii\web\Application) {
      $app->on(Application::EVENT_BEFORE_ACTION, [$tracing, 'startRequest']);
      $app->on(Application::EVENT_AFTER_REQUEST, [$tracing, 'finishRequest']);
    }

    if (class_exists(Queue::class)) {
      Event::on(Queue::class, Queue::EVENT_BEFORE_PUSH, [$tracing, 'stampJob']);
      Event::on(Queue::class, Queue::EVENT_BEFORE_EXEC, [$tracing, 'startJob']);
      Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, [$tracing, 'finishJob']);
      Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, [$tracing, 'failJob']);
    }

    $app->getLog()->targets['sentryTracing'] = new SentryTracingLogTarget(['tracing' => $tracing]);

    // A request that ends in an uncaught exception never reaches "after request".
    register_shutdown_function([$tracing, 'finishUnfinished']);

    return $tracing;
  }

  /**
   * Runs $callback inside a span of the current transaction. Without a traced
   * transaction (or without Sentry) it just runs $callback.
   */
  public static function span(string $op, string $description, callable $callback, array $data = []): mixed
  {
    $hub = SentrySdk::getCurrentHub();
    $parent = $hub->getSpan();
    if ($parent === null || !$parent->getSampled()) {
      return $callback();
    }

    $context = SpanContext::make()->setOp($op)->setDescription($description)->setData($data);
    $span = $parent->startChild($context);
    $hub->setSpan($span);
    try {
      $result = $callback();
      $span->setStatus(SpanStatus::ok());
      return $result;
    } catch (\Throwable $e) {
      $span->setStatus(SpanStatus::internalError());
      throw $e;
    } finally {
      $span->finish();
      $hub->setSpan($parent);
    }
  }

  /**
   * Tags the current transaction, e.g. with a purchase id to find all traces of one order.
   */
  public static function tag(string $key, string $value): void
  {
    $transaction = SentrySdk::getCurrentHub()->getTransaction();
    if ($transaction !== null && $transaction->getSampled()) {
      $transaction->setTags([$key => $value]);
    }
  }

  public function startRequest(ActionEvent $event): void
  {
    if ($this->transaction !== null) {
      return;
    }

    $route = $event->action->getUniqueId();
    $rate = $this->rateFor($route);
    if ($rate <= 0.0 || !$this->sentryReady()) {
      return;
    }

    $request = $this->app->getRequest();
    $context = TransactionContext::make()
      ->setName($route)
      ->setOp('http.server')
      ->setSource(TransactionSource::route())
      ->setStartTimestamp((float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)))
      ->setData(['http.request.method' => $request->getMethod()]);

    $this->start($context, $rate);
  }

  public function finishRequest(): void
  {
    if ($this->transaction === null) {
      return;
    }

    $this->transaction->setHttpStatus($this->app->getResponse()->getStatusCode());
    $this->finish();
  }

  public function stampJob(PushEvent $event): void
  {
    $span = SentrySdk::getCurrentHub()->getSpan();
    if ($span === null || !$span->getSampled() || !property_exists($event->job, 'sentryTrace')) {
      return;
    }

    $event->job->sentryTrace = Sentry\getTraceparent();
    $event->job->sentryBaggage = Sentry\getBaggage();
  }

  public function startJob(ExecEvent $event): void
  {
    if ($this->transaction !== null || !is_object($event->job)) {
      return;
    }

    $name = get_class($event->job);
    $rate = $this->rateFor($name);
    if ($rate <= 0.0 || !$this->sentryReady()) {
      return;
    }

    $trace = property_exists($event->job, 'sentryTrace') ? (string)$event->job->sentryTrace : '';
    $context = $trace !== ''
      ? Sentry\continueTrace($trace, (string)($event->job->sentryBaggage ?? ''))
      : TransactionContext::make();
    $context
      ->setName($name)
      ->setOp('queue.process')
      ->setSource(TransactionSource::task())
      ->setData(['messaging.message.id' => (string)$event->id, 'messaging.message.retry.count' => max(0, $event->attempt - 1)]);

    $this->start($context, $rate);
  }

  public function finishJob(ExecEvent $event): void
  {
    if ($this->transaction !== null) {
      $this->transaction->setStatus(SpanStatus::ok());
      $this->finish();
    }
  }

  public function failJob(ExecEvent $event): void
  {
    if ($this->transaction !== null) {
      $this->transaction->setStatus(SpanStatus::internalError());
      $this->finish();
    }
  }

  public function finishUnfinished(): void
  {
    if ($this->transaction === null) {
      return;
    }

    $response = $this->app->has('response', true) ? $this->app->getResponse() : null;
    if ($response instanceof \yii\web\Response && $response->getStatusCode() >= 400) {
      $this->transaction->setHttpStatus($response->getStatusCode());
    } else {
      $this->transaction->setStatus(SpanStatus::internalError());
    }
    $this->finish();
  }

  /**
   * One message of Yii's database profiling; a finished query becomes a span.
   *
   * @param array $message Yii log message: [text, level, category, timestamp, ...]
   */
  public function profileMessage(array $message): void
  {
    [$sql, $level, $category, $timestamp] = $message;
    $key = $category . '|' . $sql;

    if ($level === Logger::LEVEL_PROFILE_BEGIN) {
      $this->openQueries[$key][] = $timestamp;
      return;
    }

    $begin = array_pop($this->openQueries[$key]);
    if (empty($this->openQueries[$key])) {
      unset($this->openQueries[$key]);
    }

    // Queries before the transaction started (the previous job in a queue run) are not its.
    if ($begin === null || $this->transaction === null || $begin < $this->startedAt) {
      return;
    }

    $context = SpanContext::make()
      ->setOp(str_ends_with($category, '::execute') ? 'db.sql.execute' : 'db.sql.query')
      ->setDescription(self::withoutLiterals((string)$sql))
      ->setStartTimestamp($begin);
    $this->transaction->startChild($context)->finish($timestamp);
  }

  /**
   * Yii profiles the SQL with its parameter values filled in; names, e-mail addresses and
   * tokens must not go to Sentry, so quoted values are replaced.
   */
  public static function withoutLiterals(string $sql): string
  {
    $sql = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "'?'", $sql) ?? $sql;
    return mb_substr($sql, 0, 2000);
  }

  private function start(TransactionContext $context, float $rate): void
  {
    $parentSampled = $context->getParentSampled();
    $context->setSampled($parentSampled ?? (mt_rand() / mt_getrandmax() < $rate));
    if (!$context->getSampled()) {
      return;
    }

    $transaction = Sentry\startTransaction($context);
    if (!$transaction->getSampled()) {
      return;
    }

    SentrySdk::getCurrentHub()->setSpan($transaction);
    $this->transaction = $transaction;
    $this->startedAt = (float)$transaction->getStartTimestamp();
  }

  private function finish(): void
  {
    // Hand Yii's buffered profiling messages over, so the queries become spans first.
    Yii::getLogger()->flush();

    $transaction = $this->transaction;
    $this->transaction = null;
    $this->openQueries = [];
    SentrySdk::getCurrentHub()->setSpan(null);

    try {
      $transaction->finish();
    } catch (\Throwable $e) {
      // Tracing must never break the request or job.
    }
  }

  private function rateFor(string $name): float
  {
    foreach ($this->rates as $pattern => $rate) {
      if (fnmatch($pattern, $name, FNM_NOESCAPE)) {
        return (float)$rate;
      }
    }
    return 0.0;
  }

  private function sentryReady(): bool
  {
    $sentry = $this->app->get($this->sentryComponent, false);
    return $sentry instanceof CrelishSentryComponent && $sentry->isInitialized();
  }
}
