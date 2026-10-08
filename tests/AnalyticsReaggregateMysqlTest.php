<?php

/**
 * Re-aggregating a day replaces its page and element rows, so a group whose
 * views all became suspected bots disappears; a day without raw data and a day
 * before the retention period are never lowered.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/AnalyticsReaggregateMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\commands\AnalyticsAggregationController;
use giantbits\crelish\components\Analytics\AggregationParts;

const P1 = 'a1000000-0000-4000-8000-000000000001';
const P2 = 'a2000000-0000-4000-8000-000000000002';
const J1 = 'b1000000-0000-4000-8000-000000000001';
const J2 = 'b2000000-0000-4000-8000-000000000002';
const C1 = 'c1000000-0000-4000-8000-000000000001';

function aggregate(string $day): bool
{
    $controller = new AnalyticsAggregationController('analytics-aggregation', Yii::$app);
    $controller->color = false;
    ob_start();
    $ok = $controller->aggregateDate($day, AggregationParts::ALL);
    ob_end_clean();

    return $ok;
}

/** page_uuid => total_views of the day */
function pageRows(string $day): array
{
    $rows = rows('SELECT page_uuid, total_views FROM analytics_page_daily WHERE date = :d ORDER BY page_uuid', [':d' => $day]);

    return array_map('intval', array_column($rows, 'total_views', 'page_uuid'));
}

/** "element/event" => total_views of the day */
function elementRows(string $day): array
{
    $rows = rows('SELECT element_uuid, event_type, total_views FROM analytics_element_daily WHERE date = :d ORDER BY element_uuid, event_type', [':d' => $day]);
    $out = [];
    foreach ($rows as $row) {
        $out[$row['element_uuid'] . '/' . $row['event_type']] = (int)$row['total_views'];
    }

    return $out;
}

function visitRows(string $day): int
{
    return (int)scalar('SELECT COUNT(*) FROM analytics_visits_daily WHERE date = :d', [':d' => $day]);
}

function suspect(string $sessionId): void
{
    Yii::$app->db->createCommand()->update('analytics_sessions', ['is_bot' => 2], ['session_id' => $sessionId])->execute();
    Yii::$app->db->createCommand()->update('analytics_page_views', ['is_bot' => 2], ['session_id' => $sessionId])->execute();
}

function traffic(string $day, string $a, string $b): void
{
    session($a);
    session($b);
    pageView($day, '10:00:00', P1, '/a', $a);
    pageView($day, '10:01:00', P1, '/a', $b);
    pageView($day, '10:02:00', P2, '/b', $a);
    elementView($day, '10:00:00', J1, 'list', C1, $a);
    elementView($day, '10:01:00', J1, 'list', C1, $b);
    elementView($day, '10:02:00', J2, 'detail', C1, $a);
}

analyticsMysqlApp();

echo "A suspected session disappears from a re-aggregated day\n";
$day = daysAgo(2);
traffic($day, 's1', 's2');
aggregate($day);
check('first aggregation (control): pages', [P1 => 2, P2 => 1], pageRows($day));
check('first aggregation (control): elements', [J1 . '/list' => 2, J2 . '/detail' => 1], elementRows($day));
suspect('s1');
check('re-aggregation succeeds', true, aggregate($day));
check('the page seen by both is reduced, the one seen only by the suspect is gone', [P1 => 1], pageRows($day));
check('the element seen by both is reduced, the one seen only by the suspect is gone', [J1 . '/list' => 1], elementRows($day));

echo "\nA day whose raw data is gone keeps its rows\n";
$gone = daysAgo(3);
traffic($gone, 's3', 's4');
aggregate($gone);
$goneVisits = visitRows($gone);
Yii::$app->db->createCommand('DELETE FROM analytics_page_views WHERE DATE(created_at) = :d', [':d' => $gone])->execute();
Yii::$app->db->createCommand('DELETE FROM analytics_element_views WHERE DATE(created_at) = :d', [':d' => $gone])->execute();
aggregate($gone);
check('page rows are kept', [P1 => 2, P2 => 1], pageRows($gone));
check('element rows are kept', [J1 . '/list' => 2, J2 . '/detail' => 1], elementRows($gone));
check('visit rows are kept', [true, $goneVisits], [$goneVisits > 0, visitRows($gone)]);

echo "\nBot page views left behind by a cleanup are not raw data\n";
$botLeftovers = daysAgo(5);
traffic($botLeftovers, 's9', 's10');
aggregate($botLeftovers);
$leftoverVisits = visitRows($botLeftovers);
session('bot9', 1);
pageView($botLeftovers, '11:00:00', P1, '/a', 'bot9', 1);
Yii::$app->db->createCommand('DELETE FROM analytics_page_views WHERE DATE(created_at) = :d AND is_bot <> 1', [':d' => $botLeftovers])->execute();
Yii::$app->db->createCommand('DELETE FROM analytics_element_views WHERE DATE(created_at) = :d', [':d' => $botLeftovers])->execute();
aggregate($botLeftovers);
check('page rows are kept', [P1 => 2, P2 => 1], pageRows($botLeftovers));
check('element rows are kept', [J1 . '/list' => 2, J2 . '/detail' => 1], elementRows($botLeftovers));
check('visit rows are kept', [true, $leftoverVisits], [$leftoverVisits > 0, visitRows($botLeftovers)]);

echo "\nSuspected rows count as raw data\n";
$botsLeft = daysAgo(4);
traffic($botsLeft, 's5', 's6');
aggregate($botsLeft);
check('visit rows exist after the first aggregation (control)', true, visitRows($botsLeft) > 0);
suspect('s5');
suspect('s6');
aggregate($botsLeft);
check('a day whose views are all suspected has no page rows', [], pageRows($botsLeft));
check('and no element rows', [], elementRows($botsLeft));
check('and no visit rows', 0, visitRows($botsLeft));

echo "\nA day before the retention period is never lowered\n";
$old = daysAgo(40);
traffic($old, 's7', 's8');
aggregate($old);
suspect('s7');
aggregate($old);
check('page rows keep their counts', [P1 => 2, P2 => 1], pageRows($old));
check('element rows keep their counts', [J1 . '/list' => 2, J2 . '/detail' => 1], elementRows($old));

analyticsDone();
