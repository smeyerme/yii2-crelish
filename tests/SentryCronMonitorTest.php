<?php

/**
 * Console commands listed in params['crelish']['sentry_cron_monitors'] report to Sentry
 * Crons: "in progress" when they start, "ok" or "error" when they end. The first
 * check-in carries the schedule, so Sentry creates the monitor without manual setup.
 *
 * Run with:  php tests/SentryCronMonitorTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CrelishSentryComponent;
use giantbits\crelish\components\SentryCronMonitor;
use Sentry\CheckIn;
use Sentry\CheckInStatus;
use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

class SentryCronMonitorTestTransport implements TransportInterface
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

class SentryCronMonitorTestController extends \yii\console\Controller
{
    public function actionOk(): int
    {
        return 0;
    }

    public function actionFail(): int
    {
        return 1;
    }

    public function actionBoom(): int
    {
        throw new \RuntimeException('boom');
    }

    public function actionUnwatched(): int
    {
        return 0;
    }
}

/** @return CheckIn[] */
function cronTestCheckIns(SentryCronMonitorTestTransport $transport): array
{
    return array_values(array_filter(array_map(static fn(Event $e) => $e->getCheckIn(), $transport->events)));
}

$transport = new SentryCronMonitorTestTransport();

$app = new \yii\console\Application([
    'id' => 'cron-test',
    'basePath' => dirname(__DIR__),
    'controllerMap' => ['demo' => SentryCronMonitorTestController::class],
    'params' => ['crelish' => ['sentry_cron_monitors' => [
        'demo/ok' => ['schedule' => '* * * * *', 'checkinMargin' => 3, 'maxRuntime' => 5],
        'demo/fail' => ['schedule' => '0 6 * * *'],
        'demo/boom' => ['schedule' => '0 7 * * *', 'slug' => 'custom-boom'],
    ]]],
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
$monitor = SentryCronMonitor::attach($app);
check('attaches when monitors are configured', true, $monitor instanceof SentryCronMonitor);

echo "\na command that succeeds\n";
$app->runAction('demo/ok');
$checkIns = cronTestCheckIns($transport);
check('two check-ins', 2, count($checkIns));
check('slug from app id and route', 'cron-test-demo-ok', $checkIns[0]->getMonitorSlug());
check('first is in progress', CheckInStatus::inProgress(), $checkIns[0]->getStatus());
check('then ok', CheckInStatus::ok(), $checkIns[1]->getStatus());
check('same check-in id', $checkIns[0]->getId(), $checkIns[1]->getId());
check('carries the schedule', '* * * * *', $checkIns[0]->getMonitorConfig()?->getSchedule()->getValue());
check('and the margin', 3, $checkIns[0]->getMonitorConfig()?->getCheckinMargin());

echo "\na command that returns a non-zero exit code\n";
$transport->events = [];
$app->runAction('demo/fail');
$checkIns = cronTestCheckIns($transport);
check('ends with error', CheckInStatus::error(), $checkIns[1]->getStatus() ?? null);

echo "\na command that throws\n";
$transport->events = [];
try {
    $app->runAction('demo/boom');
} catch (\RuntimeException $e) {
}
check('only the start so far', 1, count(cronTestCheckIns($transport)));
check('uses the configured slug', 'custom-boom', cronTestCheckIns($transport)[0]->getMonitorSlug());
$monitor->failIfUnfinished();
$checkIns = cronTestCheckIns($transport);
check('shutdown reports error', CheckInStatus::error(), $checkIns[1]->getStatus() ?? null);
$monitor->failIfUnfinished();
check('and only once', 2, count(cronTestCheckIns($transport)));

echo "\na command without a monitor\n";
$transport->events = [];
$app->runAction('demo/unwatched');
check('sends nothing', 0, count(cronTestCheckIns($transport)));

echo "\nno monitors configured\n";
$plain = new \yii\console\Application(['id' => 'plain', 'basePath' => dirname(__DIR__)]);
check('nothing is attached', null, SentryCronMonitor::attach($plain));

shortLinkDone();
