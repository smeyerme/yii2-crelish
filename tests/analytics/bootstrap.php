<?php

/**
 * Shared helpers for the analytics tests: autoloading and the check() reporter.
 * Pure-logic tests need nothing else; database tests also require mysql.php
 * or sqlite.php from this directory.
 */

declare(strict_types=1);

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'test');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

Yii::setAlias('@giantbits/crelish', dirname(__DIR__, 2));

$failures = 0;
$passed = 0;

function check(string $name, mixed $expected, mixed $actual): void
{
    global $failures, $passed;

    if ($expected === $actual) {
        $passed++;
        echo "  ok   $name\n";
        return;
    }

    $failures++;
    echo "  FAIL $name\n";
    echo "         expected: " . var_export($expected, true) . "\n";
    echo "         actual:   " . var_export($actual, true) . "\n";
}

function checkThrows(string $name, callable $fn, string $class): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        check($name, $class, get_class($e));
        return;
    }

    check($name, $class, 'no exception');
}

function analyticsDone(): never
{
    global $failures, $passed;

    echo "\n$passed passed, $failures failed\n";
    exit($failures === 0 ? 0 : 1);
}
