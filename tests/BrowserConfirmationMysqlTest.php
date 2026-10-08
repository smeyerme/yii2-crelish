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
use giantbits\crelish\migrations\m261009_130000_add_confirmation_signals_to_analytics as SignalsMigration;

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

echo "\nTables from before the signals\n";
$signals = static function () use ($db): void {
    ob_start();
    try {
        (new SignalsMigration(['db' => $db]))->safeUp();
    } finally {
        ob_end_clean();
    }
};
$has = static fn(string $table, string $name): bool => isset($db->getTableSchema($table, true)->columns[$name]);
session('dora');
pageView('2026-10-09', '13:00:00', 'page-1', '/de', 'dora');
$dora = $viewId('dora');
check('a report with signals still confirms', true, BrowserConfirmation::confirm($db, $dora, 'dora', BrowserConfirmation::FLAG_WEBDRIVER));
check('an interaction is not recorded and does not fail', false, BrowserConfirmation::engage($db, $dora, 'dora'));

$signals();
check('page views get confirmed_flags', 'tinyint(3) unsigned', $db->getTableSchema('analytics_page_views', true)->columns['confirmed_flags']->dbType ?? null);
check('and engaged_at', true, $has('analytics_page_views', 'engaged_at'));
check('sessions get both', true, $has('analytics_sessions', 'confirmed_flags') && $has('analytics_sessions', 'engaged_at'));
check('earlier confirmations carry no signals', null, scalar("SELECT confirmed_flags FROM analytics_page_views WHERE id = $annaFirst"));
check('and keep their time', '2026-10-09 10:00:02', scalar("SELECT confirmed_at FROM analytics_page_views WHERE id = $annaFirst"));
$signals();
check('running it again changes nothing', true, $has('analytics_sessions', 'engaged_at'));

echo "\nWhat the browser says about itself\n";
$flags = static fn(string $table, string $where) => scalar("SELECT confirmed_flags FROM $table WHERE $where");
session('emil');
pageView('2026-10-09', '14:00:00', 'page-1', '/de', 'emil');
$emil = $viewId('emil');
check('an ordinary browser is confirmed', true, BrowserConfirmation::confirm($db, $emil, 'emil', 0));
check('with nothing odd about it', '0', (string)$flags('analytics_page_views', "id = $emil"));
check('nor about its session', '0', (string)$flags('analytics_sessions', "session_id = 'emil'"));

session('robot');
pageView('2026-10-09', '14:10:00', 'page-1', '/de', 'robot');
$robot = $viewId('robot');
$automated = BrowserConfirmation::FLAG_WEBDRIVER | BrowserConfirmation::FLAG_NO_LANGUAGES;
check('a driven browser is confirmed too', true, BrowserConfirmation::confirm($db, $robot, 'robot', $automated));
check('and its signals are kept', (string)$automated, (string)$flags('analytics_page_views', "id = $robot"));
check('on its session as well', (string)$automated, (string)$flags('analytics_sessions', "session_id = 'robot'"));
check('it stays a visitor; nothing acts on signals', '0', (string)scalar("SELECT is_bot FROM analytics_sessions WHERE session_id = 'robot'"));

pageView('2026-10-09', '14:11:00', 'page-2', '/de/jobs', 'robot');
BrowserConfirmation::confirm($db, $viewId('robot'), 'robot', BrowserConfirmation::FLAG_HIDDEN);
check('a session collects the signals of its pages', (string)($automated | BrowserConfirmation::FLAG_HIDDEN), (string)$flags('analytics_sessions', "session_id = 'robot'"));
check('each page keeps its own', (string)BrowserConfirmation::FLAG_HIDDEN, (string)$flags('analytics_page_views', 'id = ' . $viewId('robot')));

session('fake');
pageView('2026-10-09', '14:20:00', 'page-1', '/de', 'fake');
BrowserConfirmation::confirm($db, $viewId('fake'), 'fake', 4095);
check('signals we do not know are dropped', (string)BrowserConfirmation::KNOWN_FLAGS, (string)$flags('analytics_page_views', "session_id = 'fake'"));
session('minus');
pageView('2026-10-09', '14:21:00', 'page-1', '/de', 'minus');
BrowserConfirmation::confirm($db, $viewId('minus'), 'minus', -5);
check('a negative value counts as none', '0', (string)$flags('analytics_page_views', "session_id = 'minus'"));

echo "\nThe visitor touches the page\n";
$engaged = static fn(string $table, string $where) => scalar("SELECT engaged_at FROM $table WHERE $where");
check('before that nothing is recorded', null, $engaged('analytics_page_views', "id = $emil"));
check('the interaction is recorded', true, BrowserConfirmation::engage($db, $emil, 'emil'));
check('on the page view', true, $engaged('analytics_page_views', "id = $emil") !== null);
check('and the session', true, $engaged('analytics_sessions', "session_id = 'emil'") !== null);
$db->createCommand("UPDATE analytics_page_views SET engaged_at = '2026-10-09 14:00:07' WHERE id = $emil")->execute();
$db->createCommand("UPDATE analytics_sessions SET engaged_at = '2026-10-09 14:00:07' WHERE session_id = 'emil'")->execute();
check('a second one is not', false, BrowserConfirmation::engage($db, $emil, 'emil'));
check('and leaves the first time', '2026-10-09 14:00:07', $engaged('analytics_page_views', "id = $emil"));
pageView('2026-10-09', '14:02:00', 'page-2', '/de/jobs', 'emil');
check('a later page records its own', true, BrowserConfirmation::engage($db, $viewId('emil'), 'emil'));
check('the session keeps its first', '2026-10-09 14:00:07', $engaged('analytics_sessions', "session_id = 'emil'"));
check('a page touched before its report arrived counts as confirmed', true, $stamp('analytics_page_views', 'id = ' . $viewId('emil')) !== null);
check("someone else's page view cannot be touched", false, BrowserConfirmation::engage($db, $robot, 'emil'));
check('and stays untouched', null, $engaged('analytics_page_views', "id = $robot"));
check('nor one without a session', false, BrowserConfirmation::engage($db, $robot, ''));
check('the robot never touched anything', null, $engaged('analytics_sessions', "session_id = 'robot'"));

echo "\nA database without the analytics tables\n";
$db->createCommand('DROP TABLE analytics_sessions')->execute();
$db->createCommand('DROP TABLE analytics_page_views')->execute();
$threw = false;
try {
    $migrate();
    $signals();
} catch (\Throwable $e) {
    $threw = true;
}
check('is skipped by the migrations', false, $threw);

analyticsDone();
