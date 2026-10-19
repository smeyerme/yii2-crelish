<?php

/**
 * Which environment Sentry events carry. SENTRY_ENVIRONMENT in .env wins, so a local
 * installation reports as dev even with debug off (YII_ENV then is prod); without it,
 * params sentry_environment and then YII_ENV decide, as before.
 *
 * Run with:  php tests/SentryEnvironmentTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CrelishSentryComponent;
use giantbits\crelish\config\ComponentsConfig;

function withEnv(?string $value, array $crelishParams): array
{
    unset($_ENV['SENTRY_ENVIRONMENT']);
    putenv('SENTRY_ENVIRONMENT');
    if ($value !== null) {
        $_ENV['SENTRY_ENVIRONMENT'] = $value;
    }

    new \yii\web\Application([
        'id' => 'sentry-environment-test',
        'basePath' => __DIR__,
        'params' => ['crelish' => $crelishParams + ['sentry_dsn' => 'https://public@o0.ingest.sentry.io/0']],
        'components' => ['request' => ['cookieValidationKey' => 'x', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php']],
    ]);

    return [
        ComponentsConfig::getSentryConfig()['environment'] ?? null,
        CrelishSentryComponent::defaultEnvironment(),
    ];
}

// YII_ENV is 'test' in this harness, standing in for the prod a local install gets with debug off
check('SENTRY_ENVIRONMENT wins over YII_ENV (config)', 'dev', withEnv('dev', [])[0]);
check('SENTRY_ENVIRONMENT wins over YII_ENV (component default)', 'dev', withEnv('dev', [])[1]);
check('SENTRY_ENVIRONMENT wins over params', 'dev', withEnv('dev', ['sentry_environment' => 'prod'])[0]);
check('without it params decide', 'staging', withEnv(null, ['sentry_environment' => 'staging'])[0]);
check('without both YII_ENV decides (config)', YII_ENV, withEnv(null, [])[0]);
check('without both YII_ENV decides (component default)', YII_ENV, withEnv(null, [])[1]);
check('an empty SENTRY_ENVIRONMENT is ignored', YII_ENV, withEnv('', [])[0]);

shortLinkDone();
