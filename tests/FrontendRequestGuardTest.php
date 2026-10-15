<?php

/**
 * The frontend route crelish/frontend/run is internal: the URL rule resolves a slug
 * and dispatches to it. Called directly, with ?language=<anything>, it put the raw
 * value into Yii::$app->language and the page failed with "Invalid language code"
 * (scanner requests like language=en'nvOpzp; AND 1=1 OR ...).
 *
 * Run with:  php tests/FrontendRequestGuardTest.php
 */

declare(strict_types=1);

require __DIR__ . '/../components/FrontendRequestGuard.php';

use giantbits\crelish\components\FrontendRequestGuard;

$failures = 0;
function check(string $what, $expected, $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "  ok   $what\n";
        return;
    }
    $failures++;
    echo "  FAIL $what\n         expected: " . var_export($expected, true) . "\n         actual:   " . var_export($actual, true) . "\n";
}

echo "direct calls of the internal route\n";
check('crelish/frontend/run', true, FrontendRequestGuard::isDirectRunRequest('crelish/frontend/run'));
check('with a trailing slash', true, FrontendRequestGuard::isDirectRunRequest('crelish/frontend/run/'));
check('with something below it', true, FrontendRequestGuard::isDirectRunRequest('crelish/frontend/run/x'));
check('in other case', true, FrontendRequestGuard::isDirectRunRequest('Crelish/Frontend/Run'));
check('with a leading slash', true, FrontendRequestGuard::isDirectRunRequest('/crelish/frontend/run'));
check('a normal page is not', false, FrontendRequestGuard::isDirectRunRequest('de/aktuell'));
check('the home page is not', false, FrontendRequestGuard::isDirectRunRequest(''));
check('a page whose slug starts alike is not', false, FrontendRequestGuard::isDirectRunRequest('de/crelish-frontend-runner'));
check('the admin is not', false, FrontendRequestGuard::isDirectRunRequest('crelish/dashboard/index'));

echo "\nthe language from the query\n";
$supported = static fn(string $l): bool => in_array($l, ['de', 'en'], true);
$anything = static fn(string $l): bool => true;
check('a supported language', 'en', FrontendRequestGuard::queryLanguage('en', $supported));
check('a supported language with region', 'en-US', FrontendRequestGuard::queryLanguage('en-US', static fn($l) => in_array($l, ['en-US'], true)));
check('an unsupported language', null, FrontendRequestGuard::queryLanguage('fr', $supported));
check('nothing', null, FrontendRequestGuard::queryLanguage(null, $supported));
check('an empty value', null, FrontendRequestGuard::queryLanguage('', $supported));
check('an array (language[]=x)', null, FrontendRequestGuard::queryLanguage(['en'], $supported));
check('the scanner value', null, FrontendRequestGuard::queryLanguage("en'nvOpzp; AND 1=1 OR (<'\">iKO)),", $supported));
check('the scanner value even when every language is allowed', null, FrontendRequestGuard::queryLanguage("en'nvOpzp; AND 1=1 OR (<'\">iKO)),", $anything));
check('a path', null, FrontendRequestGuard::queryLanguage('../../etc', $anything));
check('a well-formed code when every language is allowed', 'it', FrontendRequestGuard::queryLanguage('it', $anything));

echo "\n" . ($failures === 0 ? 'all passed' : "$failures failed") . "\n";
exit($failures === 0 ? 0 : 1);
