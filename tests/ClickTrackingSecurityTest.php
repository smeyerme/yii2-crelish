<?php

/**
 * Regression tests for click tracking redirect security.
 *
 * The tracking endpoint used to redirect to the `redirect` parameter even when
 * token validation failed, which made it an open redirect. It was abused in the
 * wild to launder phishing links through a trusted domain. These tests execute
 * the real action and assert that a redirect only ever happens for a target the
 * application itself signed.
 *
 * Run with:  php tests/ClickTrackingSecurityTest.php
 */

declare(strict_types=1);

define('YII_DEBUG', false);
define('YII_ENV', 'test');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use giantbits\crelish\actions\TrackClickAction;
use giantbits\crelish\components\CrelishBaseHelper;

const TEST_SECRET = 'test-cookie-validation-key-0123456789';
const UUID = '379dafa6-5fc4-4157-9a37-cb37ca18cb88';
const GOOD_URL = 'https://www.example-company.com/karriere';
const EVIL_URL = 'https://phishing.example.net/sfpy/?=bWFrZXVw';

/**
 * Session stub so rate limiting can be exercised without PHP session state
 */
class ArraySession extends \yii\web\Session
{
    private array $data = [];

    public function open(): void
    {
    }

    public function close(): void
    {
    }

    public function getIsActive(): bool
    {
        return true;
    }

    public function get($key, $defaultValue = null)
    {
        return $this->data[$key] ?? $defaultValue;
    }

    public function set($key, $value): void
    {
        $this->data[$key] = $value;
    }
}

/**
 * Build a fresh application so each case starts from clean request state
 */
function makeApp(?string $secret = TEST_SECRET): void
{
    new \yii\web\Application([
        'id' => 'click-tracking-test',
        'basePath' => dirname(__DIR__),
        'components' => [
            'request' => [
                'cookieValidationKey' => (string)$secret,
                'scriptUrl' => '/index.php',
                'scriptFile' => __FILE__,
            ],
            'session' => ['class' => ArraySession::class],
        ],
    ]);
}

/**
 * Run the action against a given query string and return [statusCode, location]
 */
function callAction(array $query, ?string $secret = TEST_SECRET): array
{
    makeApp($secret);
    $_GET = $query;

    $controller = new \yii\web\Controller('track', Yii::$app);
    $action = new TrackClickAction('click', $controller);

    $response = $action->run();

    return [$response->statusCode, $response->headers->get('location')];
}

/**
 * Build a token with an arbitrary issue time, for expiry testing
 */
function tokenAt(string $uuid, ?string $redirect, int $timestamp): string
{
    makeApp();

    return CrelishBaseHelper::clickTokenHash($uuid, $redirect, $timestamp)
        . str_pad(base_convert((string)$timestamp, 10, 36), 10, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------------------

$failures = 0;
$passed = 0;

function check(string $name, $expected, $actual): void
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

echo "Click tracking redirect security\n";

// The legitimate case still has to work.
makeApp();
$validToken = CrelishBaseHelper::generateClickToken(UUID, GOOD_URL);
[$status, $location] = callAction([
    'uuid' => UUID,
    'type' => 'job',
    'token' => $validToken,
    'redirect' => GOOD_URL,
]);
check('signed redirect target is followed (status)', 302, $status);
check('signed redirect target is followed (location)', GOOD_URL, $location);

// The reported abuse: a token that does not validate must not redirect.
[$status, $location] = callAction([
    'uuid' => UUID,
    'type' => 'job',
    'token' => 'f7713f86bcb556fe0000t9iyi3',
    'redirect' => EVIL_URL,
]);
check('bogus token does not redirect (status)', 400, $status);
check('bogus token does not redirect (no location)', null, $location);

// A valid token must not be transferable to a different destination.
[$status, $location] = callAction([
    'uuid' => UUID,
    'type' => 'job',
    'token' => $validToken,
    'redirect' => EVIL_URL,
]);
check('swapped redirect target is rejected (status)', 400, $status);
check('swapped redirect target is rejected (no location)', null, $location);

// A ping-mode token carries no destination and must not gain one.
makeApp();
$pingToken = CrelishBaseHelper::generateClickToken(UUID, null);
[$status, $location] = callAction([
    'uuid' => UUID,
    'type' => 'job',
    'token' => $pingToken,
    'redirect' => EVIL_URL,
]);
check('ping token cannot be upgraded to a redirect (status)', 400, $status);
check('ping token cannot be upgraded to a redirect (no location)', null, $location);

// Missing token, redirect present.
[$status, $location] = callAction([
    'uuid' => UUID,
    'redirect' => EVIL_URL,
]);
check('missing token does not redirect (status)', 400, $status);
check('missing token does not redirect (no location)', null, $location);

// Full round trip through the real URL builder. The token is signed over the
// raw target, but travels through URL encoding, so encoding has to be
// symmetric or every legitimate link breaks.
// The Location header is percent-encoded where a raw byte would be illegal in a
// header, so the expected value is not always identical to the stored URL.
foreach ([
    'plain' => [
        'https://www.example-company.com/karriere',
        'https://www.example-company.com/karriere',
    ],
    'query string' => [
        'https://www.example-company.com/jobs?id=42&ref=fhb',
        'https://www.example-company.com/jobs?id=42&ref=fhb',
    ],
    'umlauts' => [
        'https://www.example-company.com/über-uns/stellenangebote',
        'https://www.example-company.com/%C3%BCber-uns/stellenangebote',
    ],
    'space and escapes' => [
        'https://www.example-company.com/a b/c+d?x=%20&y=a/b',
        'https://www.example-company.com/a%20b/c+d?x=%20&y=a/b',
    ],
    'fragment' => [
        'https://www.example-company.com/jobs#stelle-7',
        'https://www.example-company.com/jobs#stelle-7',
    ],
] as $label => [$target, $expectedLocation]) {
    makeApp();
    $url = CrelishBaseHelper::getClickTrackingUrl(UUID, 'job', $target);

    parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
    unset($params['r']);

    [$status, $location] = callAction($params);
    check("round trip survives encoding: $label (status)", 302, $status);
    check("round trip survives encoding: $label (location)", $expectedLocation, $location);
}

// A signed URL without a host is still structurally rejected.
makeApp();
$hostless = 'https:///pfad-ohne-host';
$hostlessToken = CrelishBaseHelper::generateClickToken(UUID, $hostless);
[$status, $location] = callAction([
    'uuid' => UUID,
    'token' => $hostlessToken,
    'redirect' => $hostless,
]);
check('URL without a host is rejected (status)', 400, $status);
check('URL without a host is rejected (no location)', null, $location);

// Array-valued parameters (?token[]=x) must not reach the token logic.
[$status, $location] = callAction([
    'uuid' => UUID,
    'token' => ['nested'],
    'redirect' => EVIL_URL,
]);
check('array token does not redirect (status)', 400, $status);
check('array token does not redirect (no location)', null, $location);

// Ping mode without a redirect stays quiet on success and on failure.
[$status, $location] = callAction([
    'uuid' => UUID,
    'type' => 'job',
    'token' => $pingToken,
]);
check('valid ping returns 204', 204, $status);

[$status, $location] = callAction([
    'uuid' => UUID,
    'type' => 'job',
    'token' => 'not-a-real-token-at-all-000000',
]);
check('invalid ping returns 204', 204, $status);

// Expiry is still enforced.
$expired = tokenAt(UUID, GOOD_URL, time() - 2592000 - 60);
[$status, $location] = callAction([
    'uuid' => UUID,
    'token' => $expired,
    'redirect' => GOOD_URL,
]);
check('expired token is rejected (status)', 400, $status);
check('expired token is rejected (no location)', null, $location);

// A token dated in the future is rejected rather than granting a longer life.
$future = tokenAt(UUID, GOOD_URL, time() + 86400);
[$status, $location] = callAction([
    'uuid' => UUID,
    'token' => $future,
    'redirect' => GOOD_URL,
]);
check('future-dated token is rejected (status)', 400, $status);
check('future-dated token is rejected (no location)', null, $location);

// A token minted under a different secret must not validate.
makeApp('a-completely-different-secret-value');
$foreignToken = CrelishBaseHelper::generateClickToken(UUID, GOOD_URL);
[$status, $location] = callAction([
    'uuid' => UUID,
    'token' => $foreignToken,
    'redirect' => GOOD_URL,
]);
check('token from a foreign secret is rejected (status)', 400, $status);
check('token from a foreign secret is rejected (no location)', null, $location);

// The old empty-key scheme must not be accepted any more.
$legacyHash = substr(hash_hmac('sha256', UUID . time(), ''), 0, 16);
$legacyToken = $legacyHash . str_pad(base_convert((string)time(), 10, 36), 10, '0', STR_PAD_LEFT);
[$status, $location] = callAction([
    'uuid' => UUID,
    'token' => $legacyToken,
    'redirect' => GOOD_URL,
]);
check('legacy empty-secret token is rejected (status)', 400, $status);
check('legacy empty-secret token is rejected (no location)', null, $location);

// Non-http schemes cannot be signed into a redirect.
makeApp();
$jsToken = CrelishBaseHelper::generateClickToken(UUID, 'javascript:alert(1)');
[$status, $location] = callAction([
    'uuid' => UUID,
    'token' => $jsToken,
    'redirect' => 'javascript:alert(1)',
]);
check('javascript: scheme is rejected even when signed (status)', 400, $status);
check('javascript: scheme is rejected even when signed (no location)', null, $location);

// Embedded credentials are rejected even when signed.
makeApp();
$credUrl = 'https://www.forum-holzbranche.com@phishing.example.net/';
$credToken = CrelishBaseHelper::generateClickToken(UUID, $credUrl);
[$status, $location] = callAction([
    'uuid' => UUID,
    'token' => $credToken,
    'redirect' => $credUrl,
]);
check('URL with embedded credentials is rejected (status)', 400, $status);
check('URL with embedded credentials is rejected (no location)', null, $location);

// Without a configured secret the endpoint must fail closed, not sign with ''.
makeApp('');
$threw = false;
try {
    CrelishBaseHelper::generateClickToken(UUID, GOOD_URL);
} catch (\yii\base\InvalidConfigException $e) {
    $threw = true;
}
check('missing secret raises InvalidConfigException', true, $threw);

// Rate limiting still applies within one session.
makeApp();
$rateToken = CrelishBaseHelper::generateClickToken(UUID, GOOD_URL);
$_GET = ['uuid' => UUID, 'token' => $rateToken, 'redirect' => GOOD_URL];
$controller = new \yii\web\Controller('track', Yii::$app);
$lastStatus = null;
for ($i = 0; $i < 11; $i++) {
    Yii::$app->response->clear();
    $lastStatus = (new TrackClickAction('click', $controller))->run()->statusCode;
}
check('rate limit blocks the 11th click', 400, $lastStatus);

echo "\n$passed passed, $failures failed\n";
exit($failures === 0 ? 0 : 1);
