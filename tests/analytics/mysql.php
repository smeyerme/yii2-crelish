<?php

/**
 * MySQL/MariaDB for the aggregation tests: their SQL (ON DUPLICATE KEY UPDATE,
 * GREATEST, VALUES) does not run on SQLite.
 *
 *   CRELISH_TEST_MYSQL_DSN       e.g. mysql:host=127.0.0.1;port=3306 (no dbname)
 *   CRELISH_TEST_MYSQL_USER      default root
 *   CRELISH_TEST_MYSQL_PASSWORD  default empty
 *
 * Every analyticsMysqlApp() call drops and recreates the database
 * crelish_analytics_test. Without CRELISH_TEST_MYSQL_DSN the test prints
 * SKIPPED and exits 0.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use giantbits\crelish\migrations\m261008_120000_create_analytics_visits_daily;
use yii\db\Connection;
use yii\db\Query;

const ANALYTICS_TEST_DB = 'crelish_analytics_test';

function analyticsMysqlApp(bool $withVisitsTable = true): \yii\console\Application
{
    $dsn = getenv('CRELISH_TEST_MYSQL_DSN');
    if (!$dsn) {
        echo "SKIPPED: set CRELISH_TEST_MYSQL_DSN, CRELISH_TEST_MYSQL_USER and CRELISH_TEST_MYSQL_PASSWORD\n";
        exit(0);
    }

    $user = getenv('CRELISH_TEST_MYSQL_USER') ?: 'root';
    $password = getenv('CRELISH_TEST_MYSQL_PASSWORD') ?: '';

    $pdo = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('DROP DATABASE IF EXISTS ' . ANALYTICS_TEST_DB);
    $pdo->exec('CREATE DATABASE ' . ANALYTICS_TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $app = new \yii\console\Application([
        'id' => 'analytics-test',
        'basePath' => dirname(__DIR__, 2),
        'components' => [
            'db' => [
                'class' => Connection::class,
                'dsn' => $dsn . ';dbname=' . ANALYTICS_TEST_DB,
                'username' => $user,
                'password' => $password,
                'charset' => 'utf8mb4',
                // Production runs non-strict (NO_ENGINE_SUBSTITUTION)
                'on afterOpen' => static function ($event): void {
                    $event->sender->pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
                },
            ],
        ],
    ]);

    analyticsMysqlSchema($app->db, $withVisitsTable);

    return $app;
}

function analyticsMysqlSchema(Connection $db, bool $withVisitsTable): void
{
    $db->createCommand("CREATE TABLE analytics_sessions (
        session_id varchar(100) NOT NULL,
        user_id int(11) DEFAULT NULL,
        ip_address varchar(45) DEFAULT NULL,
        user_agent varchar(255) DEFAULT NULL,
        is_bot tinyint(1) DEFAULT 0,
        bot_score tinyint(3) unsigned DEFAULT NULL,
        bot_reason varchar(255) DEFAULT NULL,
        first_page_uuid varchar(36) DEFAULT NULL,
        first_url varchar(255) DEFAULT NULL,
        created_at timestamp NULL DEFAULT current_timestamp(),
        last_activity timestamp NULL DEFAULT current_timestamp(),
        total_pages int(11) DEFAULT 1,
        PRIMARY KEY (session_id),
        KEY created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")->execute();

    $db->createCommand("CREATE TABLE analytics_page_views (
        id int(11) NOT NULL AUTO_INCREMENT,
        page_uuid varchar(36) NOT NULL,
        page_type varchar(50) NOT NULL,
        url varchar(255) NOT NULL,
        referer varchar(255) DEFAULT NULL,
        session_id varchar(100) DEFAULT NULL,
        user_id int(11) DEFAULT NULL,
        user_agent varchar(255) DEFAULT NULL,
        ip_address varchar(45) DEFAULT NULL,
        is_bot tinyint(1) DEFAULT 0,
        created_at timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        KEY idx_page_views_aggregation (created_at, page_uuid, is_bot)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")->execute();

    $db->createCommand("CREATE TABLE analytics_element_views (
        id int(11) NOT NULL AUTO_INCREMENT,
        element_uuid varchar(36) NOT NULL,
        element_type varchar(50) NOT NULL,
        page_uuid varchar(36) NOT NULL,
        session_id varchar(100) DEFAULT NULL,
        user_id int(11) DEFAULT NULL,
        type varchar(255) DEFAULT NULL,
        created_at timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        KEY idx_element_views_aggregation (created_at, element_uuid, element_type, type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")->execute();

    $db->createCommand("CREATE TABLE analytics_page_daily (
        id int(11) NOT NULL AUTO_INCREMENT,
        date date NOT NULL,
        page_uuid varchar(36) NOT NULL,
        page_url varchar(255) NOT NULL,
        total_views int(11) DEFAULT 0,
        unique_sessions int(11) DEFAULT 0,
        unique_users int(11) DEFAULT 0,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (id),
        UNIQUE KEY `idx-page_daily-unique` (date, page_uuid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();

    $db->createCommand("CREATE TABLE analytics_element_daily (
        id int(11) NOT NULL AUTO_INCREMENT,
        date date NOT NULL,
        element_uuid varchar(36) NOT NULL,
        element_type varchar(50) NOT NULL,
        page_uuid varchar(36) DEFAULT NULL,
        event_type varchar(20) NOT NULL,
        total_views int(11) DEFAULT 0,
        unique_sessions int(11) DEFAULT 0,
        unique_users int(11) DEFAULT 0,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (id),
        UNIQUE KEY `idx-element_daily-unique` (date, element_uuid, element_type, event_type, page_uuid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();

    if ($withVisitsTable && class_exists(m261008_120000_create_analytics_visits_daily::class)) {
        (new m261008_120000_create_analytics_visits_daily(['db' => $db, 'compact' => true]))->up();
    }
}

function session(string $id, int $isBot = 0, ?int $userId = null, string $createdAt = '2025-01-01 00:00:00'): void
{
    Yii::$app->db->createCommand()->insert('analytics_sessions', [
        'session_id' => $id, 'is_bot' => $isBot, 'user_id' => $userId, 'created_at' => $createdAt,
    ])->execute();
}

function pageView(string $date, string $time, string $pageUuid, string $url, ?string $sessionId, int $isBot = 0, ?int $userId = null): void
{
    Yii::$app->db->createCommand()->insert('analytics_page_views', [
        'page_uuid' => $pageUuid, 'page_type' => 'page', 'url' => $url, 'session_id' => $sessionId,
        'user_id' => $userId, 'is_bot' => $isBot, 'created_at' => "$date $time",
    ])->execute();
}

function elementView(string $date, string $time, string $elementUuid, ?string $type, string $pageUuid, string $sessionId, ?int $userId = null, string $elementType = 'job'): void
{
    Yii::$app->db->createCommand()->insert('analytics_element_views', [
        'element_uuid' => $elementUuid, 'element_type' => $elementType, 'page_uuid' => $pageUuid,
        'session_id' => $sessionId, 'user_id' => $userId, 'type' => $type, 'created_at' => "$date $time",
    ])->execute();
}

function daysAgo(int $n): string
{
    return date('Y-m-d', strtotime("-{$n} days"));
}

function rows(string $sql, array $params = []): array
{
    return Yii::$app->db->createCommand($sql, $params)->queryAll();
}

function scalar(string $sql, array $params = []): mixed
{
    return Yii::$app->db->createCommand($sql, $params)->queryScalar();
}
