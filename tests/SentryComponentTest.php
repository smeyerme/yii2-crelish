<?php

/**
 * Sentry component and log target: messages and exceptions reach the transport,
 * the capture methods return the event ID as a string, and a logged error keeps
 * the log target enabled.
 *
 * Run with:  php tests/SentryComponentTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CrelishSentryComponent;
use giantbits\crelish\components\CrelishSentryLogTarget;
use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/**
 * Keeps sent events in memory instead of sending them to sentry.io
 */
class SentryTestTransport implements TransportInterface
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

$transport = new SentryTestTransport();

new \yii\console\Application([
    'id' => 'sentry-test',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'crelishSentry' => [
            'class' => CrelishSentryComponent::class,
            'dsn' => 'https://public@o0.ingest.sentry.io/0',
            'environment' => 'test',
            'tracesSampleRate' => 0.0,
            'profilesSampleRate' => 0.0,
            'options' => ['transport' => $transport],
        ],
        'log' => [
            'targets' => [
                'sentry' => [
                    'class' => CrelishSentryLogTarget::class,
                    'levels' => ['error', 'warning'],
                    'logVars' => [],
                    'exportInterval' => 1,
                ],
            ],
        ],
    ],
]);

$sentry = Yii::$app->crelishSentry;
check('sentry initialized', true, $sentry->isInitialized());

echo "\ncaptureMessage\n";
$id = $sentry->captureMessage('message test', 'warning');
check('returns the event id as string', true, is_string($id) && strlen($id) === 32);
check('event sent', 1, count($transport->events));
check('event level', 'warning', (string)$transport->events[0]->getLevel());

echo "\ncaptureException\n";
$id = $sentry->captureException(new RuntimeException('exception test'));
check('returns the event id as string', true, is_string($id) && strlen($id) === 32);
check('event sent', 2, count($transport->events));

echo "\nlog target\n";
Yii::error('log target test', 'application');
Yii::getLogger()->flush(true);
check('logged error reaches sentry', 'log target test', isset($transport->events[2]) ? $transport->events[2]->getMessage() : null);
check('log target stays enabled', true, Yii::$app->log->targets['sentry']->enabled);

shortLinkDone();
