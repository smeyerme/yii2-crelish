<?php

/**
 * Warnings silenced with @ stay out of Sentry. Yii's FileCache reads with
 * @filemtime(), so a cache file removed between two requests used to reach Sentry as
 * "filemtime(): stat failed" although PHP and Yii both ignore it.
 *
 * Run with:  php tests/SentryErrorSuppressionTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CrelishSentryComponent;
use giantbits\crelish\components\CrelishSentryErrorHandler;
use giantbits\crelish\components\SentryActivation;
use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

class SentryErrorSuppressionTestTransport implements TransportInterface
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

$transport = new SentryErrorSuppressionTestTransport();

$app = new \yii\web\Application([
    'id' => 'sentry-suppression-test',
    'basePath' => dirname(__DIR__),
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

SentryActivation::apply($app, ['class' => CrelishSentryErrorHandler::class, 'sentryComponent' => 'crelishSentry'], null);
$handler = $app->getErrorHandler();
$missing = sys_get_temp_dir() . '/crelish-sentry-missing-' . bin2hex(random_bytes(4)) . '.bin';

// PHP 8 calls the error handler for @-silenced warnings too, with error_reporting()
// lowered to the unsilenceable fatal errors. The handler is called directly here, so
// the Sentry SDK's own error handler (which wraps it once Sentry starts) stays out.
$silenced = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;
$message = "filemtime(): stat failed for $missing";

echo "\na warning silenced with @\n";
$previous = error_reporting($silenced);
$result = $handler->handleError(E_WARNING, $message, __FILE__, __LINE__);
error_reporting($previous);
check('the handler leaves it to PHP', false, $result);
check('nothing reaches Sentry', 0, count($transport->events));

echo "\nthe same warning without @\n";
$thrown = null;
try {
    $handler->handleError(E_WARNING, $message, __FILE__, __LINE__);
} catch (\yii\base\ErrorException $e) {
    $thrown = $e;
}
check('Yii still turns it into an exception', true, $thrown !== null);
check('and Sentry gets it', 1, count($transport->events));

$handler->unregister();
shortLinkDone();
