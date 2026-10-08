<?php

/**
 * Daily visit rows: site and owner rows per event type, no collisions,
 * idempotent normal runs, repair never lowers, missing table tolerated.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/AnalyticsVisitsMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\commands\AnalyticsAggregationController;
use giantbits\crelish\components\Analytics\AggregationParts;
use giantbits\crelish\components\Analytics\VisitsAggregator;
use giantbits\crelish\migrations\m261008_120000_create_analytics_visits_daily;

const C1 = 'c1000000-0000-4000-8000-000000000001';
const C1_UPPER = 'C1000000-0000-4000-8000-000000000001';
const C2 = 'c2000000-0000-4000-8000-000000000002';
const J1 = 'b1000000-0000-4000-8000-000000000001';
const J2 = 'b2000000-0000-4000-8000-000000000002';
const J3 = 'b3000000-0000-4000-8000-000000000003';
const P1 = 'a1000000-0000-4000-8000-000000000001';
const P2 = 'a2000000-0000-4000-8000-000000000002';

function visit(string $day, string $source, string $owner, string $event): ?int
{
    $value = scalar(
        'SELECT unique_sessions FROM analytics_visits_daily WHERE date = :d AND source = :s AND owner_uuid = :o AND event_type = :e',
        [':d' => $day, ':s' => $source, ':o' => $owner, ':e' => $event]
    );

    return $value === false ? null : (int)$value;
}

function rowCount(string $day): int
{
    return (int)scalar('SELECT COUNT(*) FROM analytics_visits_daily WHERE date = :d', [':d' => $day]);
}

function fixtures(string $day): void
{
    foreach (['s1', 's2', 's3', 's5', 's6', 's7'] as $id) {
        session($id, 0, $id === 's1' ? 7 : null);
    }
    session('bot', 1);
    elementView($day, '08:00:00', J1, 'list', C1, 's1', 7);
    elementView($day, '08:01:00', J2, 'list', C1, 's1', 7);
    elementView($day, '08:02:00', J1, 'detail', C1, 's1', 7);
    elementView($day, '08:03:00', J2, 'detail', C1, 's2');
    elementView($day, '08:04:00', J3, 'list', C2, 's3');
    elementView($day, '08:05:00', J1, 'list', C1, 'bot');
    elementView($day, '08:06:00', J1, null, C1, 's5');
    elementView($day, '08:07:00', J1, 'list', '', 's6');
    elementView($day, '08:08:00', J1, 'detail', C1_UPPER, 's7');
    pageView($day, '08:00:00', P1, '/a', 's2');
    pageView($day, '08:01:00', P1, '/a?x', 's3');
    pageView($day, '08:02:00', P2, '/b', 's3');
    pageView($day, '08:03:00', P1, '/a', 'bot', 1);
}

$day = daysAgo(2);

echo "Rows for one day\n";
analyticsMysqlApp();
fixtures($day);
(new VisitsAggregator(Yii::$app->db))->aggregate($day);
check('site page visits exclude bots', 2, visit($day, 'pages', '', ''));
check('site element visits, any event', 6, visit($day, 'elements', '', ''));
check('site element visits, list', 3, visit($day, 'elements', '', 'list'));
check('site element visits, detail', 3, visit($day, 'elements', '', 'detail'));
check('company visits, any event, one visitor seeing several jobs counts once', 4, visit($day, 'elements', C1, ''));
check('company visits, list', 1, visit($day, 'elements', C1, 'list'));
check('company visits, detail, both UUID spellings in one row', 3, visit($day, 'elements', C1, 'detail'));
check('other company', 1, visit($day, 'elements', C2, ''));
check('logged-in users are counted', 1, (int)scalar("SELECT unique_users FROM analytics_visits_daily WHERE date = :d AND source = 'elements' AND owner_uuid = '' AND event_type = ''", [':d' => $day]));
check('a missing type creates no per-type row', 0, (int)scalar("SELECT COUNT(*) FROM analytics_visits_daily WHERE date = :d AND event_type NOT IN ('', 'list', 'detail')", [':d' => $day]));
check('one row per owner and event however the UUID is spelled', 1, (int)scalar("SELECT COUNT(*) FROM analytics_visits_daily WHERE date = :d AND owner_uuid = :o AND event_type = 'detail'", [':d' => $day, ':o' => C1]));
check('rows: site pages 1, site elements 3, C1 3, C2 2', 9, rowCount($day));

echo "\nNormal runs are idempotent and drop stale rows\n";
Yii::$app->db->createCommand()->insert('analytics_visits_daily', ['date' => $day, 'source' => 'elements', 'owner_uuid' => 'dead', 'event_type' => '', 'unique_sessions' => 5])->execute();
(new VisitsAggregator(Yii::$app->db))->aggregate($day);
check('a rerun writes the same rows', 9, rowCount($day));
check('a stale row is gone', null, visit($day, 'elements', 'dead', ''));

echo "\nRepair never lowers\n";
Yii::$app->db->createCommand()->insert('analytics_visits_daily', ['date' => $day, 'source' => 'elements', 'owner_uuid' => 'dead', 'event_type' => '', 'unique_sessions' => 5])->execute();
Yii::$app->db->createCommand("UPDATE analytics_visits_daily SET unique_sessions = 10 WHERE owner_uuid = :o AND event_type = ''", [':o' => C1])->execute();
Yii::$app->db->createCommand("UPDATE analytics_visits_daily SET unique_sessions = 1 WHERE owner_uuid = '' AND source = 'pages'")->execute();
(new VisitsAggregator(Yii::$app->db))->aggregate($day, true);
check('repair keeps a higher stored value', 10, visit($day, 'elements', C1, ''));
check('repair raises a lower stored value', 2, visit($day, 'pages', '', ''));
check('repair deletes nothing', 5, visit($day, 'elements', 'dead', ''));

echo "\nEmpty day\n";
check('a day without traffic writes no rows', 0, (new VisitsAggregator(Yii::$app->db))->aggregate(daysAgo(20)));

echo "\nA day whose raw data is gone keeps its rows\n";
$gone = daysAgo(20);
Yii::$app->db->createCommand()->insert('analytics_visits_daily', ['date' => $gone, 'source' => 'pages', 'owner_uuid' => '', 'event_type' => '', 'unique_sessions' => 42])->execute();
check('a normal run writes nothing for it', 0, (new VisitsAggregator(Yii::$app->db))->aggregate($gone));
check('the stored row survives a normal run', 42, visit($gone, 'pages', '', ''));
session('botonly', 1);
pageView($gone, '10:00:00', P1, '/a', 'botonly', 1);
elementView($gone, '10:00:00', J1, 'list', C1, 'botonly');
(new VisitsAggregator(Yii::$app->db))->aggregate($gone);
check('bot traffic alone is raw data: the day has no visits, the stale row is replaced', null, visit($gone, 'pages', '', ''));

echo "\nAn invalid date is stored as the day it was normalised to\n";
pageView('2026-03-02', '10:00:00', P1, '/a', 's2');
(new VisitsAggregator(Yii::$app->db))->aggregate('2026-02-30');
check('2026-02-30 is stored as 2026-03-02, the day it counted', 1, visit('2026-03-02', 'pages', '', ''));
check('no row for an impossible or zero date', 0, (int)scalar("SELECT COUNT(*) FROM analytics_visits_daily WHERE date < '2000-01-01'"));

echo "\nOlder than the retention period: visits are only ever raised\n";
$old = daysAgo(45);
session('old1');
session('old2');
pageView($old, '10:00:00', P1, '/a', 'old1');
pageView($old, '10:05:00', P1, '/a', 'old2');
Yii::$app->db->createCommand()->insert('analytics_visits_daily', ['date' => $old, 'source' => 'pages', 'owner_uuid' => '', 'event_type' => '', 'unique_sessions' => 50])->execute();
Yii::$app->db->createCommand()->insert('analytics_visits_daily', ['date' => $old, 'source' => 'elements', 'owner_uuid' => 'dead', 'event_type' => '', 'unique_sessions' => 5])->execute();
$controller = new AnalyticsAggregationController('analytics-aggregation', Yii::$app);
check('visits part succeeds for an old day', true, $controller->aggregateDate($old, [AggregationParts::VISITS]));
check('a higher stored value is kept (only part of the raw data is left)', 50, visit($old, 'pages', '', ''));
check('no row of the old day is deleted', 5, visit($old, 'elements', 'dead', ''));
$controller->retentionDays = 60;
$controller->aggregateDate($old, [AggregationParts::VISITS]);
check('within a longer retention period the day is recomputed normally', 2, visit($old, 'pages', '', ''));

echo "\nThrough aggregateDate\n";
$controller = new AnalyticsAggregationController('analytics-aggregation', Yii::$app);
Yii::$app->db->createCommand('DELETE FROM analytics_visits_daily')->execute();
check('visits part succeeds', true, $controller->aggregateDate($day, [AggregationParts::VISITS]));
check('visits part wrote the rows', 9, rowCount($day));

echo "\nMissing table\n";
analyticsMysqlApp(false);
fixtures($day);
check('table reported missing', false, VisitsAggregator::tableExists(Yii::$app->db));
$controller = new AnalyticsAggregationController('analytics-aggregation', Yii::$app);
check('all parts still succeed', true, $controller->aggregateDate($day, AggregationParts::ALL));
check('pages were aggregated', 3, (int)scalar('SELECT SUM(total_views) FROM analytics_page_daily WHERE date = :d', [':d' => $day]));

echo "\nMigration\n";
$migration = new m261008_120000_create_analytics_visits_daily(['db' => Yii::$app->db, 'compact' => true]);
$migration->up();
check('migration creates the table', true, VisitsAggregator::tableExists(Yii::$app->db));
check('migration can run again', null, $migration->up() === false ? 'failed' : null);

analyticsDone();
