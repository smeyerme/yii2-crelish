<?php

/**
 * With params['crelish']['sentry_logs'] every console command run is recorded in Sentry
 * Logs: what it logged while running, then one entry with route, exit code and duration.
 * Queue runs that found no job send nothing: cron starts them every minute.
 *
 * Run with:  php tests/SentryConsoleLogsTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CrelishSentryComponent;
use giantbits\crelish\components\SentryConsoleLogs;
use Sentry\Event;
use Sentry\Logs\Log;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use yii\queue\ExecEvent;
use yii\queue\Queue;

class SentryConsoleLogsTestTransport implements TransportInterface
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
}

class SentryConsoleLogsTestController extends \yii\console\Controller
{
    public function actionOk(): int
    {
        Yii::info('3 postings imported', 'jobfeed');
        Yii::info('Opening DB connection', 'yii\db\Connection::open');
        return 0;
    }

    public function actionFail(): int
    {
        Yii::warning('feed answered 503', 'jobfeed');
        return 1;
    }

    public function actionBoom(): int
    {
        throw new \RuntimeException('boom');
    }
}

// Behaves like yii2-queue's run command without a real queue behind it
class SentryConsoleLogsTestQueueCommand extends \yii\queue\cli\Command
{
    public $verbose = false;
    public $isolate = false;
    public int $jobsToRun = 0;

    protected function isWorkerAction($actionID)
    {
        return false;
    }

    public function actionRun(): int
    {
        for ($i = 0; $i < $this->jobsToRun; $i++) {
            \yii\base\Event::trigger(Queue::class, Queue::EVENT_AFTER_EXEC, new ExecEvent());
        }
        return 0;
    }
}

/** @return Log[] */
function consoleLogs(SentryConsoleLogsTestTransport $transport): array
{
    $logs = [];
    foreach ($transport->events as $event) {
        array_push($logs, ...$event->getLogs());
    }
    return $logs;
}

function consoleLogAttr(Log $log, string $key): mixed
{
    return $log->attributes()->toSimpleArray()[$key] ?? null;
}

$transport = new SentryConsoleLogsTestTransport();

$app = new \yii\console\Application([
    'id' => 'console-logs-test',
    'basePath' => dirname(__DIR__),
    'controllerMap' => [
        'demo' => SentryConsoleLogsTestController::class,
        'queue' => SentryConsoleLogsTestQueueCommand::class,
    ],
    'params' => ['crelish' => ['sentry_logs' => true]],
    'components' => [
        'crelishSentry' => [
            'class' => CrelishSentryComponent::class,
            'dsn' => 'https://public@o0.ingest.sentry.io/0',
            'environment' => 'test',
            'tracesSampleRate' => 0.0,
            'profilesSampleRate' => 0.0,
            'options' => ['transport' => $transport],
        ],
    ],
]);
$app->get('crelishSentry');
$consoleLogs = SentryConsoleLogs::attach($app);
check('attaches when sentry_logs is on', true, $consoleLogs instanceof SentryConsoleLogs);

echo "\na command that succeeds\n";
$app->runAction('demo/ok');
$logs = consoleLogs($transport);
check('its message and the summary', ['3 postings imported', 'demo/ok finished'], array_map(static fn(Log $l) => $l->getBody(), $logs));
check('framework messages are left out', false, in_array('Opening DB connection', array_map(static fn(Log $l) => $l->getBody(), $logs), true));
check('the message names the command', 'demo/ok', consoleLogAttr($logs[0], 'command'));
check('and its category', 'jobfeed', consoleLogAttr($logs[0], 'category'));
check('the summary is info', 'info', (string)$logs[1]->getLevel());
check('with the exit code', 0, consoleLogAttr($logs[1], 'exit_code'));
check('and the duration', true, is_int(consoleLogAttr($logs[1], 'duration_ms')));

echo "\na command that returns a non-zero exit code\n";
$transport->events = [];
$app->runAction('demo/fail');
$logs = consoleLogs($transport);
check('the warning is a warning', 'warn', (string)$logs[0]->getLevel());
check('the summary is an error', 'error', (string)$logs[1]->getLevel());
check('naming the exit code', 'demo/fail ended with exit code 1', $logs[1]->getBody());

echo "\na command that throws\n";
$transport->events = [];
try {
    $app->runAction('demo/boom');
} catch (\RuntimeException $e) {
}
check('nothing before shutdown', 0, count(consoleLogs($transport)));
$consoleLogs->finishUnfinished();
$logs = consoleLogs($transport);
check('shutdown sends an error', 'error', (string)($logs[0] ?? null)?->getLevel());
$consoleLogs->finishUnfinished();
check('only once', 1, count(consoleLogs($transport)));

echo "\nqueue runs\n";
$transport->events = [];
$app->runAction('queue/run');
check('an idle run sends nothing', 0, count(consoleLogs($transport)));
$queue = $app->createController('queue')[0];
$queue->jobsToRun = 2;
$queue->runAction('run');
$logs = consoleLogs($transport);
check('a run with jobs is recorded', 'queue/run finished', ($logs[0] ?? null)?->getBody());
check('with the number of jobs', 2, ($logs[0] ?? null) ? consoleLogAttr($logs[0], 'jobs') : null);

echo "\nsentry_logs not set\n";
$plain = new \yii\console\Application(['id' => 'plain', 'basePath' => dirname(__DIR__)]);
check('nothing is attached', null, SentryConsoleLogs::attach($plain));

shortLinkDone();
