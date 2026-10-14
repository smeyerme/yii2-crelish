<?php

/**
 * Sentry on web applications: the error handler Yii registered in the constructor is
 * replaced by the Sentry one, and the Sentry log target joins the live dispatcher.
 * Before SentryActivation, crelish only redefined the components in its bootstrap,
 * so PHP kept calling Yii's handler and no web exception ever reached Sentry.
 *
 * Run with:  php tests/SentryActivationTest.php
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

class SentryActivationTestTransport implements TransportInterface
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

function currentExceptionHandler(): mixed
{
    $current = set_exception_handler(null);
    set_exception_handler($current);
    return $current;
}

$transport = new SentryActivationTestTransport();

$app = new \yii\web\Application([
    'id' => 'sentry-activation-test',
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

$yiiHandler = $app->getErrorHandler();
check('before: Yii registered its own handler in the constructor', true, currentExceptionHandler() === [$yiiHandler, 'handleException']);

$errorHandlerConfig = [
    'class' => CrelishSentryErrorHandler::class,
    'sentryComponent' => 'crelishSentry',
];
$logTargetConfig = [
    'class' => CrelishSentryLogTarget::class,
    'sentryComponent' => 'crelishSentry',
    'levels' => ['error', 'warning'],
    'exportInterval' => 1,
    'logVars' => [],
];

echo "\nredefining the component alone (the old bootstrap behaviour)\n";
$app->set('errorHandler', $errorHandlerConfig);
check('PHP still calls Yii\'s handler', true, currentExceptionHandler() === [$yiiHandler, 'handleException']);
$app->set('errorHandler', $yiiHandler);

echo "\nSentryActivation::apply\n";
SentryActivation::apply($app, $errorHandlerConfig, $logTargetConfig);
$sentryHandler = $app->getErrorHandler();
check('the error handler component is the Sentry one', true, $sentryHandler instanceof CrelishSentryErrorHandler);
check('and PHP calls it for uncaught exceptions', true, currentExceptionHandler() === [$sentryHandler, 'handleException']);
check('the live dispatcher has the Sentry target', true, ($app->getLog()->targets['sentry'] ?? null) instanceof CrelishSentryLogTarget);
check('and the logger uses that dispatcher', true, Yii::getLogger()->dispatcher === $app->getLog());

Yii::error('activation test', 'application');
Yii::getLogger()->flush(true);
check('a logged error reaches Sentry', 'activation test', isset($transport->events[0]) ? $transport->events[0]->getMessage() : null);

echo "\nnothing to activate\n";
SentryActivation::apply($app, null, null);
check('leaves the handler in place', true, $app->getErrorHandler() === $sentryHandler);

$sentryHandler->unregister();
shortLinkDone();
