<?php

/**
 * Shared harness for the short link tests.
 *
 * Builds a fresh web application per scenario with an in-memory SQLite
 * database, the real shortlink migration and the analytics tables in their
 * production shape (page_uuid NOT NULL, varchar(255) user agent/first URL).
 */

declare(strict_types=1);

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'test');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

use giantbits\crelish\components\CrelishAnalyticsComponent;
use giantbits\crelish\migrations\m260924_120000_create_shortlink_table;
use yii\helpers\ArrayHelper;

Yii::setAlias('@giantbits/crelish', dirname(__DIR__, 2));

/**
 * Session stub so analytics and flash messages work without PHP session state
 */
class ShortLinkTestSession extends \yii\web\Session
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

    public function has($key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove($key)
    {
        $value = $this->data[$key] ?? null;
        unset($this->data[$key]);
        return $value;
    }
}

/**
 * Identity stub; tests always run as a guest
 */
class ShortLinkTestIdentity implements \yii\web\IdentityInterface
{
    public static function findIdentity($id)
    {
        return null;
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return null;
    }

    public function getId()
    {
        return null;
    }

    public function getAuthKey()
    {
        return null;
    }

    public function validateAuthKey($authKey)
    {
        return false;
    }
}

/**
 * Build a fresh application.
 *
 * @param array $params  merged into params['crelish'] (shortLinks is enabled by default)
 * @param array $server  $_SERVER overrides (HTTP_HOST, REQUEST_URI, HTTP_USER_AGENT, ...)
 * @param array $config  merged into the application config
 */
function shortLinkApp(array $params = [], array $server = [], array $config = []): \yii\web\Application
{
    $_GET = [];
    $_SERVER = array_merge($_SERVER, [
        'HTTP_HOST' => 'forum-holzbau.test',
        'SERVER_NAME' => 'forum-holzbau.test',
        'SERVER_PORT' => '443',
        'HTTPS' => 'on',
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/',
        'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => __FILE__,
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
        'HTTP_ACCEPT_LANGUAGE' => '',
    ], $server);

    $crelish = array_replace_recursive([
        'langprefix' => true,
        'languages' => ['de'],
        'entryPoint' => ['ctype' => 'page', 'path' => 'home', 'slug' => 'home'],
        'shortLinks' => ['enabled' => true],
    ], $params);

    $app = new \yii\web\Application(ArrayHelper::merge([
        'id' => 'shortlink-test',
        'basePath' => dirname(__DIR__, 2),
        'language' => 'de',
        'params' => ['crelish' => $crelish],
        'components' => [
            'request' => [
                'cookieValidationKey' => 'shortlink-test-key',
                'scriptUrl' => '/index.php',
                'scriptFile' => __FILE__,
            ],
            'session' => ['class' => ShortLinkTestSession::class],
            'user' => ['identityClass' => ShortLinkTestIdentity::class, 'enableSession' => false],
            'db' => [
                'class' => \yii\db\Connection::class,
                'dsn' => 'sqlite::memory:',
                'pdoClass' => class_exists(\Pdo\Sqlite::class) ? \Pdo\Sqlite::class : null,
                'on afterOpen' => static function ($event): void {
                    shortLinkSqliteFunctions($event->sender->pdo);
                },
            ],
            'cache' => ['class' => \yii\caching\ArrayCache::class],
            'urlManager' => ['enablePrettyUrl' => true, 'showScriptName' => false],
            'crelishAnalytics' => ['class' => CrelishAnalyticsComponent::class],
            // Model validators call Yii::t('crelish', ...); without a registered
            // message source for that category Yii throws InvalidConfigException.
            // Point at the package's own message files, with no missing-translation
            // handler, so an untranslated string is returned as-is (no DeepL calls).
            'i18n' => [
                'translations' => [
                    'crelish*' => [
                        'class' => \yii\i18n\PhpMessageSource::class,
                        'basePath' => dirname(__DIR__, 2) . '/messages',
                        'sourceLanguage' => 'en',
                        'fileMap' => ['crelish' => 'crelish.php'],
                    ],
                ],
            ],
        ],
    ], $config));

    shortLinkSchema($app->db);

    return $app;
}

/**
 * MySQL's NOW() is used by the analytics inserts; SQLite needs it registered.
 */
function shortLinkSqliteFunctions(\PDO $pdo): void
{
    $now = static fn(): string => date('Y-m-d H:i:s');

    if ($pdo instanceof \Pdo\Sqlite) {
        $pdo->createFunction('NOW', $now, 0);
        return;
    }

    $pdo->sqliteCreateFunction('NOW', $now, 0);
}

/**
 * Real shortlink migration plus the analytics tables in their production shape.
 */
function shortLinkSchema(\yii\db\Connection $db): void
{
    (new m260924_120000_create_shortlink_table(['db' => $db, 'compact' => true]))->up();

    $db->createCommand()->createTable('analytics_sessions', [
        'session_id' => 'varchar(100) NOT NULL PRIMARY KEY',
        'user_id' => 'integer NULL',
        'ip_address' => 'varchar(45) NULL',
        'user_agent' => 'varchar(255) NULL',
        'is_bot' => 'smallint DEFAULT 0',
        'first_page_uuid' => 'varchar(36) NULL',
        'first_url' => 'varchar(255) NULL',
        'created_at' => 'datetime DEFAULT CURRENT_TIMESTAMP',
        'last_activity' => 'datetime DEFAULT CURRENT_TIMESTAMP',
        'total_pages' => 'integer DEFAULT 1',
    ])->execute();

    $db->createCommand()->createTable('analytics_element_views', [
        'id' => 'integer PRIMARY KEY AUTOINCREMENT',
        'element_uuid' => 'varchar(36) NOT NULL',
        'element_type' => 'varchar(50) NOT NULL',
        'page_uuid' => 'varchar(36) NOT NULL',
        'session_id' => 'varchar(100) NULL',
        'user_id' => 'integer NULL',
        'type' => 'varchar(255) NULL',
        'created_at' => 'datetime DEFAULT CURRENT_TIMESTAMP',
    ])->execute();

    $db->createCommand()->createTable('analytics_element_daily', [
        'id' => 'integer PRIMARY KEY AUTOINCREMENT',
        'date' => 'date NOT NULL',
        'element_uuid' => 'varchar(36) NOT NULL',
        'element_type' => 'varchar(50) NOT NULL',
        'page_uuid' => 'varchar(36) NULL',
        'event_type' => 'varchar(50) NOT NULL',
        'total_views' => 'integer DEFAULT 0',
        'unique_sessions' => 'integer DEFAULT 0',
        'unique_users' => 'integer DEFAULT 0',
    ])->execute();
}

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

function shortLinkDone(): never
{
    global $failures, $passed;

    echo "\n$passed passed, $failures failed\n";
    exit($failures === 0 ? 0 : 1);
}
