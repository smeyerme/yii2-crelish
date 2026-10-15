<?php

/**
 * Sentry gets problems at their real level and no PHP deprecation notices.
 *
 * Yii hands log targets the level as a Logger::LEVEL_* integer; the log target looked
 * it up by name, so every log message reached Sentry as "info". Deprecation notices
 * (Twig, mbstring) are upgrade hints for developers, not production problems.
 *
 * Run with:  php tests/SentryNoiseTest.php
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

class SentryNoiseTestTransport implements TransportInterface
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
class SentryNoiseTestHandler extends CrelishSentryErrorHandler
{
    protected function renderException($exception)
    {
    }
}

$transport = new SentryNoiseTestTransport();

$app = new \yii\web\Application([
    'id' => 'sentry-noise-test',
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
    ['class' => SentryNoiseTestHandler::class, 'sentryComponent' => 'crelishSentry'],
    ['class' => CrelishSentryLogTarget::class, 'sentryComponent' => 'crelishSentry', 'levels' => ['error', 'warning'], 'exportInterval' => 1, 'logVars' => []]
);
$handler = $app->getErrorHandler();
$app->get('crelishSentry');

function noiseTestLevel(SentryNoiseTestTransport $transport): ?string
{
    return isset($transport->events[0]) ? (string)$transport->events[0]->getLevel() : null;
}

echo "\nlog messages keep their level\n";
Yii::error('logged error', 'application');
Yii::getLogger()->flush(true);
check('an error is an error', 'error', noiseTestLevel($transport));

$transport->events = [];
Yii::warning('logged warning', 'application');
Yii::getLogger()->flush(true);
check('a warning is a warning', 'warning', noiseTestLevel($transport));

echo "\ndeprecation notices stay out\n";
$transport->events = [];
// Yii still turns them into exceptions when error_reporting includes them; that is unchanged.
try {
    $handler->handleError(E_USER_DEPRECATED, 'Since twig/twig 3.21: something is deprecated', __FILE__, __LINE__);
} catch (\yii\base\ErrorException $e) {
}
Yii::getLogger()->flush(true);
check('E_USER_DEPRECATED', 0, count($transport->events));
try {
    $handler->handleError(E_DEPRECATED, 'mb_convert_encoding(): Handling HTML entities via mbstring is deprecated', __FILE__, __LINE__);
} catch (\yii\base\ErrorException $e) {
}
Yii::getLogger()->flush(true);
check('E_DEPRECATED', 0, count($transport->events));

echo "\nreal warnings still count\n";
try {
    $handler->handleError(E_WARNING, 'Attempt to read property "created" on null', __FILE__, __LINE__);
} catch (\yii\base\ErrorException $e) {
}
check('E_WARNING reaches Sentry', 1, count($transport->events));

echo "\nclient errors (4xx) stay out, server errors count\n";
// Uncaught, the way PHP hands them over; Yii's error handler also logs them, so flush.
// Handling an exception unregisters the handler, so it is registered again each time.
function noiseTestUncaught(SentryNoiseTestTransport $transport, $handler, \Throwable $exception): int
{
    $transport->events = [];
    $handler->handleException($exception);
    $handler->register();
    Yii::getLogger()->flush(true);
    return count($transport->events);
}
check('404 Not Found', 0, noiseTestUncaught($transport, $handler, new \yii\web\NotFoundHttpException('Asset not found.')));
check('400 Bad Request', 0, noiseTestUncaught($transport, $handler, new \yii\web\BadRequestHttpException()));
check('403 Forbidden', 0, noiseTestUncaught($transport, $handler, new \yii\web\ForbiddenHttpException()));
check('405 Method Not Allowed', 0, noiseTestUncaught($transport, $handler, new \yii\web\MethodNotAllowedHttpException()));
check('500 reaches Sentry', 1, noiseTestUncaught($transport, $handler, new \yii\web\ServerErrorHttpException()));
check('503 reaches Sentry', 1, noiseTestUncaught($transport, $handler, new \yii\web\HttpException(503)));

$handler->unregister();
shortLinkDone();
