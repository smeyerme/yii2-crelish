<?php

/**
 * Analytics user_id holds crelish user UUIDs. It was int(11): on a strict MySQL every
 * page view of a logged-in user failed, on a non-strict one MySQL stored garbage
 * (0, the leading digits, 2147483647). After the migration the column is varchar(36)
 * and the daily aggregation counts UUID users, also those starting with a letter.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/AnalyticsUserIdMigrationMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\commands\AnalyticsAggregationController;
use giantbits\crelish\components\Analytics\AggregationParts;
use giantbits\crelish\migrations\m261015_120000_analytics_user_id_as_uuid as UserIdMigration;

const PAGE = 'a1000000-0000-4000-8000-000000000001';
const JOB = 'b1000000-0000-4000-8000-000000000001';
const DIGIT_E_USER = '5e27db9c-88ba-4903-a035-ac9a3ddbcd90';
const LETTER_USER = 'c3a1f0aa-1111-4000-8000-000000000001';
const TABLES = ['analytics_page_views', 'analytics_sessions', 'analytics_element_views'];

analyticsMysqlApp();
$db = Yii::$app->db;

$userIdType = static fn(string $table): string => $db->getTableSchema($table, true)->columns['user_id']->dbType;
$migrate = static function () use ($db): void {
    ob_start();
    try {
        (new UserIdMigration(['db' => $db]))->safeUp();
    } finally {
        ob_end_clean();
    }
};

echo "Before the migration (production shape, non-strict)\n";
foreach (TABLES as $table) {
    check("$table.user_id is an int", 'int(11)', $userIdType($table));
}
session('old', 0, DIGIT_E_USER);
check('a UUID is stored as a meaningless number', '2147483647', (string)scalar("SELECT user_id FROM analytics_sessions WHERE session_id = 'old'"));

echo "\nStrict mode rejects the UUID outright\n";
$db->createCommand("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'")->execute();
$failed = false;
try {
    session('strict', 0, DIGIT_E_USER);
} catch (\yii\db\Exception $e) {
    $failed = str_contains($e->getMessage(), 'Out of range');
}
check('Out of range for a 5e27… UUID', true, $failed);

echo "\nThe migration\n";
$migrate();
foreach (TABLES as $table) {
    check("$table.user_id is varchar(36)", 'varchar(36)', $userIdType($table));
}
check('old meaningless numbers are cleared', null, scalar("SELECT user_id FROM analytics_sessions WHERE session_id = 'old'"));

echo "\nUUIDs are stored as they are (still strict)\n";
$day = '2026-10-14';
session('s1', 0, DIGIT_E_USER);
session('s2', 0, LETTER_USER);
pageView($day, '09:00:00', PAGE, '/a', 's1', 0, DIGIT_E_USER);
pageView($day, '09:01:00', PAGE, '/a', 's2', 0, LETTER_USER);
pageView($day, '09:02:00', PAGE, '/a', 's2', 0, LETTER_USER);
elementView($day, '09:00:00', JOB, 'detail', PAGE, 's1', DIGIT_E_USER);
elementView($day, '09:01:00', JOB, 'detail', PAGE, 's2', LETTER_USER);
check('the page view keeps the UUID', DIGIT_E_USER, scalar("SELECT user_id FROM analytics_page_views WHERE session_id = 's1'"));

echo "\nDaily aggregation counts UUID users\n";
$controller = new AnalyticsAggregationController('analytics-aggregation', Yii::$app);
$controller->interactive = false;
$controller->color = false;
ob_start();
$controller->aggregateDate($day, [AggregationParts::PAGES, AggregationParts::ELEMENTS]);
ob_end_clean();
check('page: two users, one starting with a letter', '2', (string)scalar('SELECT unique_users FROM analytics_page_daily WHERE date = :d', [':d' => $day]));
check('element: two users', '2', (string)scalar('SELECT unique_users FROM analytics_element_daily WHERE date = :d', [':d' => $day]));

echo "\nRunning it again\n";
$migrate();
check('changes nothing', DIGIT_E_USER, scalar("SELECT user_id FROM analytics_page_views WHERE session_id = 's1'"));

echo "\nA database without the analytics tables\n";
foreach (TABLES as $table) {
    $db->createCommand("DROP TABLE $table")->execute();
}
$migrate();
check('is left alone', null, $db->getTableSchema('analytics_sessions', true));

analyticsDone();
