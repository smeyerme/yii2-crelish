<?php

/**
 * In-memory SQLite for the read-side tests (plain SELECT, SUM, GROUP BY).
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function analyticsSqliteApp(bool $withVisitsTable = true): \yii\console\Application
{
    $app = new \yii\console\Application([
        'id' => 'analytics-sqlite-test',
        'basePath' => dirname(__DIR__, 2),
        'components' => [
            'db' => [
                'class' => \yii\db\Connection::class,
                'dsn' => 'sqlite::memory:',
                'pdoClass' => class_exists(\Pdo\Sqlite::class) ? \Pdo\Sqlite::class : null,
            ],
        ],
    ]);

    if ($withVisitsTable) {
        $app->db->createCommand()->createTable('analytics_visits_daily', [
            'id' => 'integer PRIMARY KEY AUTOINCREMENT',
            'date' => 'date NOT NULL',
            'source' => 'varchar(16) NOT NULL',
            'owner_uuid' => "varchar(36) NOT NULL DEFAULT ''",
            'event_type' => "varchar(50) NOT NULL DEFAULT ''",
            'unique_sessions' => 'integer NOT NULL DEFAULT 0',
            'unique_users' => 'integer NOT NULL DEFAULT 0',
        ])->execute();
    }

    return $app;
}

function visitRow(string $date, string $source, string $owner, string $event, int $sessions, int $users = 0): void
{
    Yii::$app->db->createCommand()->insert('analytics_visits_daily', [
        'date' => $date, 'source' => $source, 'owner_uuid' => $owner, 'event_type' => $event,
        'unique_sessions' => $sessions, 'unique_users' => $users,
    ])->execute();
}
