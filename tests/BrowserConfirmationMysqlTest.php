<?php

/**
 * Browser confirmation: a page view recorded while rendering is stamped once
 * the visitor's browser reports back. Only the visitor's own page views can be
 * stamped, a stamp is never moved, and nothing else about the row changes.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/BrowserConfirmationMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\components\Analytics\BrowserConfirmation;
use giantbits\crelish\migrations\m261009_120000_add_confirmed_at_to_analytics as ConfirmedAtMigration;

analyticsMysqlApp();
$db = Yii::$app->db;

$column = static fn(string $table) => $db->getTableSchema($table, true)->columns['confirmed_at'] ?? null;
$migrate = static function () use ($db): void {
    ob_start();
    try {
        (new ConfirmedAtMigration(['db' => $db]))->safeUp();
    } finally {
        ob_end_clean();
    }
};
$viewId = static fn(string $session): int => (int)scalar('SELECT MAX(id) FROM analytics_page_views WHERE session_id = :s', [':s' => $session]);
$stamp = static fn(string $table, string $where) => scalar("SELECT confirmed_at FROM $table WHERE $where");

echo "Tables from before the confirmation\n";
session('anna');
pageView('2026-10-09', '10:00:00', 'page-1', '/de', 'anna');
check('page views have no confirmed_at', null, $column('analytics_page_views'));
check('confirming does nothing and does not fail', false, BrowserConfirmation::confirm($db, $viewId('anna'), 'anna'));

$migrate();
check('page views get confirmed_at', 'timestamp', $column('analytics_page_views')?->dbType);
check('that may be empty', true, $column('analytics_page_views')?->allowNull);
check('sessions get confirmed_at', 'timestamp', $column('analytics_sessions')?->dbType);
check('existing page views are unconfirmed', null, $stamp('analytics_page_views', "session_id = 'anna'"));
check('existing sessions are unconfirmed', null, $stamp('analytics_sessions', "session_id = 'anna'"));
check('and keep their time', '2026-10-09 10:00:00', scalar("SELECT created_at FROM analytics_page_views WHERE session_id = 'anna'"));

$migrate();
check('running it again changes nothing', 'timestamp', $column('analytics_sessions')?->dbType);

echo "\nA visitor's browser reports back\n";
session('ben');
pageView('2026-10-09', '11:00:00', 'page-1', '/de', 'ben');
$annaFirst = $viewId('anna');

check('the page view is confirmed', true, BrowserConfirmation::confirm($db, $annaFirst, 'anna'));
check('it carries a time', true, $stamp('analytics_page_views', "id = $annaFirst") !== null);
check('so does the session', true, $stamp('analytics_sessions', "session_id = 'anna'") !== null);
check('the page view keeps its own time', '2026-10-09 10:00:00', scalar("SELECT created_at FROM analytics_page_views WHERE id = $annaFirst"));
check('and its state', '0', (string)scalar("SELECT is_bot FROM analytics_page_views WHERE id = $annaFirst"));
check('another visitor stays unconfirmed', null, $stamp('analytics_sessions', "session_id = 'ben'"));

echo "\nA second report for the same page view\n";
$db->createCommand("UPDATE analytics_page_views SET confirmed_at = '2026-10-09 10:00:02' WHERE id = $annaFirst")->execute();
$db->createCommand("UPDATE analytics_sessions SET confirmed_at = '2026-10-09 10:00:02' WHERE session_id = 'anna'")->execute();
check('is not counted', false, BrowserConfirmation::confirm($db, $annaFirst, 'anna'));
check('and leaves the first time', '2026-10-09 10:00:02', $stamp('analytics_page_views', "id = $annaFirst"));

echo "\nA later page of a confirmed session\n";
pageView('2026-10-09', '10:05:00', 'page-2', '/de/jobs', 'anna');
check('is confirmed', true, BrowserConfirmation::confirm($db, $viewId('anna'), 'anna'));
check('the session keeps its first confirmation', '2026-10-09 10:00:02', $stamp('analytics_sessions', "session_id = 'anna'"));

echo "\nSomeone else's page view\n";
check('cannot be confirmed', false, BrowserConfirmation::confirm($db, $viewId('ben'), 'anna'));
check('and stays unconfirmed', null, $stamp('analytics_page_views', "session_id = 'ben'"));
check('nor can one that does not exist', false, BrowserConfirmation::confirm($db, 999999, 'anna'));
check('nor one with a nonsense id', false, BrowserConfirmation::confirm($db, 0, 'anna'));
check('nor without a session', false, BrowserConfirmation::confirm($db, $viewId('ben'), ''));

echo "\nA bot that runs a browser\n";
session('crawler', 1);
pageView('2026-10-09', '12:00:00', 'page-1', '/de', 'crawler', 1);
check('is confirmed like anyone', true, BrowserConfirmation::confirm($db, $viewId('crawler'), 'crawler'));
check('and stays a bot', '1', (string)scalar("SELECT is_bot FROM analytics_sessions WHERE session_id = 'crawler'"));

echo "\nA database without the analytics tables\n";
$db->createCommand('DROP TABLE analytics_sessions')->execute();
$db->createCommand('DROP TABLE analytics_page_views')->execute();
$threw = false;
try {
    $migrate();
} catch (\Throwable $e) {
    $threw = true;
}
check('is skipped by the migration', false, $threw);

analyticsDone();
