<?php

/**
 * One problem, one Sentry event. crelish captures warnings and uncaught exceptions in
 * CrelishSentryErrorHandler; the Sentry SDK's own error, exception and fatal error
 * listeners and the log target must not report the same problem again.
 *
 * Run with:  php tests/SentryDuplicateEventsTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CrelishSentryComponent;
use giantbits\crelish\components\CrelishSentryErrorHandler;
use giantbits\crelish\components\CrelishSentryLogTarget;
use giantbits\crelish\components\SentryActivation;
use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

class SentryDuplicateEventsTestTransport implements TransportInterface
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

// The error page cannot be sent from the CLI; only what reaches Sentry matters here.
class SentryDuplicateEventsTestHandler extends CrelishSentryErrorHandler
{
    protected function renderException($exception)
    {
    }
}

function duplicateTestDescribe(array $events): string
{
    return implode(' / ', array_map(static function (Event $event): string {
        $exceptions = $event->getExceptions();
        return $exceptions ? $exceptions[0]->getType() . ': ' . $exceptions[0]->getValue() : 'message: ' . strtok((string)$event->getMessage(), "\n");
    }, $events));
}

function duplicateTestCurrentExceptionHandler(): callable
{
    $current = set_exception_handler(null);
    set_exception_handler($current);
    return $current;
}

$transport = new SentryDuplicateEventsTestTransport();

$app = new \yii\web\Application([
    'id' => 'sentry-duplicate-test',
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
    ['class' => SentryDuplicateEventsTestHandler::class, 'sentryComponent' => 'crelishSentry'],
    ['class' => CrelishSentryLogTarget::class, 'sentryComponent' => 'crelishSentry', 'levels' => ['error', 'warning'], 'exportInterval' => 1, 'logVars' => []]
);
$handler = $app->getErrorHandler();

// In production the component starts on first use, after the handler is registered.
$app->get('crelishSentry');

$missing = sys_get_temp_dir() . '/crelish-sentry-missing-' . bin2hex(random_bytes(4)) . '.bin';

echo "\na warning that the code catches\n";
try {
    filemtime($missing);
} catch (\yii\base\ErrorException $e) {
}
Yii::getLogger()->flush(true);
check('reaches Sentry once', 1, count($transport->events));
echo '       ', duplicateTestDescribe($transport->events), "\n";

echo "\nan uncaught exception\n";
$transport->events = [];
duplicateTestCurrentExceptionHandler()(new \RuntimeException('uncaught boom'));
Yii::getLogger()->flush(true);
check('reaches Sentry once', 1, count($transport->events));
echo '       ', duplicateTestDescribe($transport->events), "\n";

echo "\na logged error\n";
$transport->events = [];
Yii::error('logged problem', 'application');
Yii::getLogger()->flush(true);
check('reaches Sentry once', 1, count($transport->events));

echo "\na caught exception the code logs\n";
$transport->events = [];
try {
    throw new \LogicException('caught and logged');
} catch (\LogicException $e) {
    Yii::error($e, 'application');
}
Yii::getLogger()->flush(true);
check('reaches Sentry once', 1, count($transport->events));
check('as an exception with its stack trace', \LogicException::class, isset($transport->events[0]) && $transport->events[0]->getExceptions() ? $transport->events[0]->getExceptions()[0]->getType() : null);

echo "\na warning silenced with @\n";
$transport->events = [];
@filemtime($missing);
Yii::getLogger()->flush(true);
check('stays out of Sentry', 0, count($transport->events));

$handler->unregister();
shortLinkDone();
