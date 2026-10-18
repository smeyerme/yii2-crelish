<?php

/**
 * The GA measurement call must survive request data that is not valid UTF-8 (a bot sent
 * a Latin-1 User-Agent), and a failed send must not be retried at shutdown, where the
 * session can no longer be started because the response is already out.
 *
 * Run with:  php tests/AnalyticsServiceTest.php
 */

declare(strict_types=1);

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'test');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use giantbits\crelish\components\Analytics\AnalyticsService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use yii\log\Logger;

class AnalyticsServiceTestSession extends \yii\web\Session
{
    public int $gets = 0;
    private array $data = [];

    public function open(): void
    {
    }

    public function getIsActive(): bool
    {
        return true;
    }

    public function get($key, $defaultValue = null)
    {
        $this->gets++;
        return $this->data[$key] ?? $defaultValue;
    }

    public function set($key, $value): void
    {
        $this->data[$key] = $value;
    }
}

class AnalyticsServiceTestFailingClient extends Client
{
    public int $calls = 0;

    public function postAsync($uri, array $options = []): \GuzzleHttp\Promise\PromiseInterface
    {
        $this->calls++;
        throw new \RuntimeException('network down');
    }
}

$failures = 0;
$passed = 0;

function check(string $name, $expected, $actual): void
{
    global $failures, $passed;
    if ($expected === $actual) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failures++;
        echo "  FAIL $name\n       expected: " . var_export($expected, true) . "\n       actual:   " . var_export($actual, true) . "\n";
    }
}

function makeService(Client $client): AnalyticsService
{
    $_SERVER['HTTP_USER_AGENT'] = "HelvyraBot/1.0 (+https://helvyra.ch/bot; v\xE9rification des canaux de recrutement publi\xE9s)";
    Yii::setLogger(null);
    $_SERVER['HTTP_HOST'] = 'forum-holzbranche.com';
    $_SERVER['REQUEST_URI'] = '/impressum';
    new \yii\web\Application([
        'id' => 'analytics-service-test',
        'basePath' => __DIR__,
        'params' => ['crelish' => ['ga_measurement_id' => 'G-TEST', 'ga_api_secret' => 'secret']],
        'components' => [
            'request' => ['cookieValidationKey' => 'x', 'scriptUrl' => '/index.php', 'scriptFile' => __FILE__],
            'session' => ['class' => AnalyticsServiceTestSession::class],
        ],
    ]);
    Yii::$app->params['content']['systitle'] = 'Impressum';

    $service = new AnalyticsService(['debug' => false]);
    (new ReflectionProperty($service, 'client'))->setValue($service, $client);
    return $service;
}

function errorsLogged(): array
{
    $errors = [];
    foreach (Yii::getLogger()->messages as [$text, $level]) {
        if ($level === Logger::LEVEL_ERROR) {
            $errors[] = $text;
        }
    }
    return $errors;
}

// A Latin-1 User-Agent is sent with the invalid bytes replaced, not dropped with an error
$history = [];
$stack = HandlerStack::create(new MockHandler([new Response(204)]));
$stack->push(Middleware::history($history));
$service = makeService(new Client(['handler' => $stack]));
$service->trackPageView();
\GuzzleHttp\Promise\Utils::queue()->run();

check('invalid UTF-8 logs no error', [], errorsLogged());
check('the event is sent', 1, count($history));
$body = $history ? json_decode((string)$history[0]['request']->getBody(), true) : null;
check('the user agent arrives with the bytes replaced',
    "HelvyraBot/1.0 (+https://helvyra.ch/bot; v\u{FFFD}rification des canaux de recrutement publi\u{FFFD}s)",
    $body['events'][0]['params']['user_agent'] ?? null);
check('the body is sent as JSON', 'application/json', $history ? $history[0]['request']->getHeaderLine('Content-Type') : null);

// A failed send is dropped, so the shutdown flush does not touch the session again
$client = new AnalyticsServiceTestFailingClient();
$service = makeService($client);
$service->trackPageView();
$getsAfterSend = Yii::$app->session->gets;
$service->flush();

check('the failed send is logged once', 1, count(errorsLogged()));
check('the shutdown flush does not send again', 1, $client->calls);
check('the shutdown flush does not read the session', $getsAfterSend, Yii::$app->session->gets);

echo "$passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
