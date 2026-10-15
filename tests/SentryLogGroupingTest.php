<?php

/**
 * Log messages are grouped in Sentry by category and level. They carry no stack trace
 * of their own, so Sentry grouped them by the frame where the SDK attached one and
 * merged unrelated messages (HTTP client logs, click tracking) into a single issue.
 *
 * Run with:  php tests/SentryLogGroupingTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CrelishSentryComponent;
use giantbits\crelish\components\CrelishSentryLogTarget;
use giantbits\crelish\components\SentryActivation;
use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

class SentryLogGroupingTestTransport implements TransportInterface
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

$transport = new SentryLogGroupingTestTransport();

$app = new \yii\web\Application([
    'id' => 'sentry-grouping-test',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'request' => ['cookieValidationKey' => 'test', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php'],
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

SentryActivation::apply(
    $app,
    null,
    ['class' => CrelishSentryLogTarget::class, 'sentryComponent' => 'crelishSentry', 'levels' => ['error', 'warning'], 'exportInterval' => 1, 'logVars' => []]
);
$app->get('crelishSentry');

function groupingTestLog(string $level, string $message, string $category): void
{
    $level === 'error' ? Yii::error($message, $category) : Yii::warning($message, $category);
    Yii::getLogger()->flush(true);
}

groupingTestLog('warning', 'Upstream answered 502 for job 1', 'jobfeed');
groupingTestLog('warning', 'Upstream answered 503 for job 2', 'jobfeed');
groupingTestLog('warning', 'Mail bounced for user 7', 'mailer');
groupingTestLog('error', 'Upstream answered 502 for job 3', 'jobfeed');

[$first, $second, $otherCategory, $otherLevel] = array_map(static fn(Event $e) => $e->getFingerprint(), $transport->events);

echo "\nfingerprints\n";
check('same category and level share a group', $first, $second);
check('another category gets its own', false, $first === $otherCategory);
check('another level gets its own', false, $first === $otherLevel);
check('fingerprint names category and level', ['crelish-log', 'jobfeed', 'warning'], $first);

echo "\nscope stays clean\n";
$transport->events = [];
\Sentry\captureMessage('plain message');
check('later events keep default grouping', [], $transport->events[0]->getFingerprint());

shortLinkDone();
