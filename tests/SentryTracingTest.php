<?php

/**
 * Requests and queue jobs listed in params['crelish']['sentry_traces'] are sent to Sentry
 * as transactions, with their database queries and project spans; everything else is not.
 * A job queued during a traced request continues that request's trace.
 *
 * Run with:  php tests/SentryTracingTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CarriesSentryTrace;
use giantbits\crelish\components\CrelishSentryComponent;
use giantbits\crelish\components\SentryTracing;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

class SentryTracingTestTransport implements TransportInterface
{
    /** @var Event[] */
    public array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;
        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }

    /** @return Event[] */
    public function transactions(): array
    {
        return array_values(array_filter($this->events, static fn(Event $e) => $e->getType() === EventType::transaction()));
    }
}

class SentryTracingTestJob extends \yii\base\BaseObject implements \yii\queue\JobInterface
{
    use CarriesSentryTrace;

    public function execute($queue)
    {
        Yii::$app->db->createCommand("SELECT 'job'")->queryScalar();
    }
}

class SentryTracingTestController extends \yii\web\Controller
{
    public function actionPay()
    {
        Yii::$app->db->createCommand("SELECT * FROM buyer WHERE email = 'anna@example.com'")->queryAll();
        Yii::$app->db->createCommand("INSERT INTO buyer (email) VALUES ('ben@example.com')")->execute();
        SentryTracing::tag('purchase_id', '42');
        return SentryTracing::span('http.client', 'POST /v1/checkout/sessions', static fn() => 'paid');
    }

    public function actionQueue()
    {
        Yii::$app->queue->push(new SentryTracingTestJob());
        return 'queued';
    }

    public function actionFail()
    {
        Yii::$app->crelishSentry->captureException(new \RuntimeException('Stripe timeout'));
        return 'failed';
    }

    public function actionPage()
    {
        Yii::$app->db->createCommand("SELECT 1")->queryScalar();
        return 'page';
    }
}

$transport = new SentryTracingTestTransport();

$app = new \yii\web\Application([
    'id' => 'sentry-tracing-test',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerMap' => ['checkout' => SentryTracingTestController::class, 'site' => SentryTracingTestController::class],
    'components' => [
        'request' => ['cookieValidationKey' => 'test', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php'],
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'queue' => ['class' => \yii\queue\sync\Queue::class],
        'crelishSentry' => [
            'class' => CrelishSentryComponent::class,
            'dsn' => 'https://public@o0.ingest.sentry.io/0',
            'environment' => 'test',
            'tracesSampleRate' => 0.0,
            'profilesSampleRate' => 0.0,
            'options' => ['transport' => $transport],
        ],
    ],
    'params' => ['crelish' => ['sentry_traces' => [
        'checkout/*' => 1.0,
        'SentryTracingTest*' => 1.0,
    ]]],
]);
$app->db->createCommand('CREATE TABLE buyer (email TEXT)')->execute();
// Setup queries before the test request are not part of it
Yii::getLogger()->flush();
$app->get('crelishSentry');
$tracing = SentryTracing::attach($app);

function tracingTestRequest(\yii\web\Application $app, string $route): mixed
{
    $result = $app->runAction($route);
    $app->trigger(\yii\base\Application::EVENT_AFTER_REQUEST);
    return $result;
}

echo "\na traced route\n";
$result = tracingTestRequest($app, 'checkout/pay');
$transactions = $transport->transactions();
check('becomes one transaction', 1, count($transactions));
$transaction = $transactions[0] ?? null;
check('named after the route', 'checkout/pay', $transaction?->getTransaction());
$spans = $transaction ? $transaction->getSpans() : [];
$byOp = [];
foreach ($spans as $span) {
    $byOp[$span->getOp()][] = $span->getDescription();
}
check('the SELECT is a span', ["SELECT * FROM buyer WHERE email = '?'"], $byOp['db.sql.query'] ?? null);
check('the INSERT is a span', ["INSERT INTO buyer (email) VALUES ('?')"], $byOp['db.sql.execute'] ?? null);
check('the project span is there', ['POST /v1/checkout/sessions'], $byOp['http.client'] ?? null);
check('no e-mail address reaches Sentry', false, str_contains(json_encode(array_map(static fn($s) => $s->getDescription(), $spans)), '@'));
check('tagged with the purchase', '42', $transaction?->getTags()['purchase_id'] ?? null);
check('span() returns the callback result', 'paid', $result);

echo "\nanything else\n";
$transport->events = [];
tracingTestRequest($app, 'site/page');
check('is not traced', 0, count($transport->transactions()));
check('span() still runs the callback', 'x', SentryTracing::span('op', 'outside', static fn() => 'x'));

echo "\na job queued by a traced request\n";
$transport->events = [];
tracingTestRequest($app, 'checkout/queue');
$app->queue->run();
$transactions = $transport->transactions();
check('request and job are two transactions', 2, count($transactions));
[$request, $job] = $transactions + [null, null];
check('the job is named after its class', SentryTracingTestJob::class, $job?->getTransaction());
check('the job continues the request trace', (string)$request?->getContexts()['trace']['trace_id'], (string)($job?->getContexts()['trace']['trace_id'] ?? ''));
check('the job query is a span', ["SELECT '?'"], array_map(static fn($s) => $s->getDescription(), $job ? $job->getSpans() : []));

echo "\nan error during a traced request\n";
$transport->events = [];
tracingTestRequest($app, 'checkout/fail');
$error = array_values(array_filter($transport->events, static fn(Event $e) => $e->getType() === EventType::event()))[0] ?? null;
$transaction = $transport->transactions()[0] ?? null;
check('belongs to the request trace', (string)($transaction?->getContexts()['trace']['trace_id'] ?? 'none'), (string)($error?->getContexts()['trace']['trace_id'] ?? 'missing'));

echo "\nliterals\n";
check('escaped quotes', "WHERE a = '?' AND b = '?'", SentryTracing::withoutLiterals("WHERE a = 'it''s' AND b = 'x\\'y'"));

shortLinkDone();
