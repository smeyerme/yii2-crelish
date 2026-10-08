<?php

/**
 * The bot detection needs analytics_sessions.bot_score and bot_reason. No
 * migration ever created them: portals that had them got them by hand, and on
 * one that did not, the nightly bot detection failed on its first query
 * ("Unknown column 'bot_score'") every night, so nothing was scored and no bot
 * session was ever deleted.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/BotScoreMigrationMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\migrations\m261008_140000_add_bot_score_to_analytics_sessions as BotScoreMigration;

analyticsMysqlApp();
$db = Yii::$app->db;

$columns = static fn(): array => $db->getTableSchema('analytics_sessions', true)->columns;
$indexes = static fn(): array => array_column(rows("SHOW INDEX FROM analytics_sessions WHERE Column_name = 'bot_score'"), 'Key_name');
$migrate = static function (string $method) use ($db): void {
    ob_start();
    try {
        (new BotScoreMigration(['db' => $db]))->$method();
    } finally {
        ob_end_clean();
    }
};

echo "A sessions table from before the bot scores\n";
$db->createCommand('ALTER TABLE analytics_sessions DROP COLUMN bot_score, DROP COLUMN bot_reason')->execute();
session('visitor', 0);
session('bot', 1);
check('has no bot_score column', false, isset($columns()['bot_score']));

$migrate('safeUp');
check('gets bot_score', true, isset($columns()['bot_score']));
check('as an unsigned tinyint', 'tinyint(3) unsigned', $columns()['bot_score']->dbType);
check('that may be empty', true, $columns()['bot_score']->allowNull);
check('gets bot_reason', 'varchar(255)', $columns()['bot_reason']->dbType ?? null);
check('and an index on the score', ['idx_bot_score'], $indexes());
check('its sessions are unscored', '2', (string)scalar('SELECT COUNT(*) FROM analytics_sessions WHERE bot_score IS NULL'));
check('and keep their state', '1', (string)scalar("SELECT is_bot FROM analytics_sessions WHERE session_id = 'bot'"));

echo "\nA sessions table that already has them\n";
$db->createCommand("UPDATE analytics_sessions SET bot_score = 65, bot_reason = 'kept' WHERE session_id = 'visitor'")->execute();
$migrate('safeUp');
check('is left alone', '65', (string)scalar("SELECT bot_score FROM analytics_sessions WHERE session_id = 'visitor'"));
check('with one index, not two', ['idx_bot_score'], $indexes());

echo "\nA table with the columns but without the index\n";
$db->createCommand('ALTER TABLE analytics_sessions DROP INDEX idx_bot_score')->execute();
$migrate('safeUp');
check('gets the index', ['idx_bot_score'], $indexes());

echo "\nA database without the analytics tables\n";
$db->createCommand('DROP TABLE analytics_sessions')->execute();
$threw = false;
try {
    $migrate('safeUp');
} catch (\Throwable $e) {
    $threw = true;
}
check('is skipped without an error', false, $threw);

analyticsDone();
