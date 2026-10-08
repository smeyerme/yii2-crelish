<?php

/**
 * Daily aggregation against MySQL: correct totals, independent parts,
 * --only / --pagesOnly, repair mode that never lowers, failing exit codes.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/AnalyticsDailyMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\commands\AnalyticsAggregationController;
use giantbits\crelish\components\Analytics\AggregationParts;

const P1 = 'a1000000-0000-4000-8000-000000000001';
const J1 = 'b1000000-0000-4000-8000-000000000001';
const C1 = 'c1000000-0000-4000-8000-000000000001';

function controller(): AnalyticsAggregationController
{
    $controller = new AnalyticsAggregationController('analytics-aggregation', Yii::$app);
    $controller->interactive = false;
    $controller->color = false;

    return $controller;
}

function fixtures(string $day): void
{
    session('s1');
    session('s2');
    session('bot', 1);
    pageView($day, '09:00:00', P1, '/jobs?x=1', 's1');
    pageView($day, '09:05:00', P1, '/jobs?x=2', 's2');
    pageView($day, '09:10:00', P1, '/jobs?x=3', 'bot', 1);
    elementView($day, '09:00:00', J1, 'list', C1, 's1');
    elementView($day, '09:01:00', J1, 'detail', C1, 's1');
    elementView($day, '09:02:00', J1, 'detail', C1, 's2');
    elementView($day, '09:03:00', J1, 'detail', C1, 'bot');
    elementView($day, '09:04:00', J1, null, C1, 's2');
}

function pageTotal(string $day): int
{
    return (int)scalar('SELECT COALESCE(SUM(total_views), 0) FROM analytics_page_daily WHERE date = :d', [':d' => $day]);
}

function elementTotal(string $day, ?string $event = null): int
{
    $sql = 'SELECT COALESCE(SUM(total_views), 0) FROM analytics_element_daily WHERE date = :d';
    $params = [':d' => $day];
    if ($event !== null) {
        $sql .= ' AND event_type = :e';
        $params[':e'] = $event;
    }

    return (int)scalar($sql, $params);
}

$day = daysAgo(3);

echo "Normal run\n";
analyticsMysqlApp(false);
fixtures($day);
check('both parts succeed', true, controller()->aggregateDate($day, [AggregationParts::PAGES, AggregationParts::ELEMENTS]));
check('one page row however many URL variants', 1, (int)scalar('SELECT COUNT(*) FROM analytics_page_daily WHERE date = :d', [':d' => $day]));
check('page views exclude bots', 2, pageTotal($day));
check('element detail views exclude the bot session', 2, elementTotal($day, 'detail'));
check('an element view without type is kept under an empty event type', 1, elementTotal($day, ''));
check('element views in total', 4, elementTotal($day));

echo "\nRerun overwrites\n";
Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 100')->execute();
controller()->aggregateDate($day, [AggregationParts::PAGES]);
check('a normal rerun overwrites a higher stored count', 2, pageTotal($day));

echo "\nRepair never lowers\n";
Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 100')->execute();
controller()->aggregateDate($day, [AggregationParts::PAGES], true);
check('repair keeps a higher stored count', 100, pageTotal($day));
Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 1')->execute();
controller()->aggregateDate($day, [AggregationParts::PAGES], true);
check('repair raises a lower stored count', 2, pageTotal($day));

echo "\nParts are independent\n";
Yii::$app->db->createCommand('DELETE FROM analytics_page_daily')->execute();
Yii::$app->db->createCommand('RENAME TABLE analytics_element_daily TO analytics_element_daily_gone')->execute();
check('a failing element part reports failure', false, controller()->aggregateDate($day, [AggregationParts::PAGES, AggregationParts::ELEMENTS]));
check('the page part still ran', 2, pageTotal($day));
check('backfill exits non-zero when a day fails', 1, controller()->runAction('backfill', ['3']));
Yii::$app->db->createCommand('RENAME TABLE analytics_element_daily_gone TO analytics_element_daily')->execute();

echo "\n--only and --pagesOnly\n";
Yii::$app->db->createCommand('DELETE FROM analytics_page_daily')->execute();
Yii::$app->db->createCommand('DELETE FROM analytics_element_daily')->execute();
check('--only=pages exits 0', 0, controller()->runAction('daily', [$day, 'only' => 'pages']));
check('--only=pages wrote pages', 2, pageTotal($day));
check('--only=pages left elements alone', 0, elementTotal($day));
Yii::$app->db->createCommand('DELETE FROM analytics_page_daily')->execute();
check('--pagesOnly=1 exits 0', 0, controller()->runAction('daily', [$day, 'pagesOnly' => '1']));
check('--pagesOnly=1 wrote pages', 2, pageTotal($day));
check('--pagesOnly=1 left elements alone', 0, elementTotal($day));
check('an unknown --only part is a usage error', 64, controller()->runAction('daily', [$day, 'only' => 'sessions']));
check('an impossible date is a usage error', 64, controller()->runAction('daily', ['2026-02-30']));
check('a date not written as Y-m-d is a usage error', 64, controller()->runAction('daily', ['yesterday']));

echo "\nBackfill dry run writes nothing\n";
analyticsMysqlApp();
fixtures($day);
controller()->aggregateDate($day, AggregationParts::ALL);
$snapshot = static fn(): array => [
    rows('SELECT * FROM analytics_page_daily ORDER BY id'),
    rows('SELECT * FROM analytics_element_daily ORDER BY id'),
    rows('SELECT * FROM analytics_visits_daily ORDER BY id'),
];
Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 100')->execute();
Yii::$app->db->createCommand('UPDATE analytics_element_daily SET total_views = 100')->execute();
Yii::$app->db->createCommand('UPDATE analytics_visits_daily SET unique_sessions = 100')->execute();
Yii::$app->db->createCommand()->insert('analytics_visits_daily', ['date' => $day, 'source' => 'elements', 'owner_uuid' => 'dead', 'event_type' => '', 'unique_sessions' => 5])->execute();
$before = $snapshot();
check('backfill --dryRun=1 exits 0', 0, controller()->runAction('backfill', ['5', 'dryRun' => '1']));
check('backfill --dryRun=1 leaves page, element and visit rows unchanged', $before, $snapshot());
check('a real backfill would have changed them', 0, controller()->runAction('backfill', ['5']));
check('(so the dry run check is not vacuous)', false, $before === $snapshot());

analyticsDone();
