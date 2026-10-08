<?php

/**
 * Cleanup: whole days, verification against raw data, repair that only raises,
 * keeping and reporting days that cannot be repaired.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/AnalyticsCleanupMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\commands\AnalyticsAggregationController;
use giantbits\crelish\components\Analytics\AggregationParts;

const P1 = 'a1000000-0000-4000-8000-000000000001';
const J1 = 'b1000000-0000-4000-8000-000000000001';
const C1 = 'c1000000-0000-4000-8000-000000000001';

/** Refuses to repair one day, to exercise the "kept" path */
class NoRepairController extends AnalyticsAggregationController
{
    public string $broken = '';

    public function aggregateDate(string $date, array $parts, bool $repair = false): bool
    {
        return $repair && $date === $this->broken ? false : parent::aggregateDate($date, $parts, $repair);
    }
}

/** Throws while repairing one day, to exercise a verification exception */
class ThrowingRepairController extends NoRepairController
{
    public function aggregateDate(string $date, array $parts, bool $repair = false): bool
    {
        if ($repair && $date === $this->broken) {
            throw new \RuntimeException('simulated failure');
        }

        return parent::aggregateDate($date, $parts, $repair);
    }
}

function cleanup(array $options = [], string $class = AnalyticsAggregationController::class, string $broken = ''): int
{
    $controller = new $class('analytics-aggregation', Yii::$app);
    $controller->interactive = false;
    $controller->color = false;
    if ($controller instanceof NoRepairController) {
        $controller->broken = $broken;
    }

    return $controller->runAction('cleanup', array_merge(['retentionDays' => '30', 'force' => '1'], $options));
}

function aggregate(string $day): void
{
    $controller = new AnalyticsAggregationController('analytics-aggregation', Yii::$app);
    $controller->aggregateDate($day, AggregationParts::ALL);
}

function rawPages(string $day): int
{
    return (int)scalar('SELECT COUNT(*) FROM analytics_page_views WHERE created_at >= :s AND created_at < :e', [':s' => "$day 00:00:00", ':e' => date('Y-m-d', strtotime("$day +1 day")) . ' 00:00:00']);
}

function storedPages(string $day): int
{
    return (int)scalar('SELECT COALESCE(SUM(total_views), 0) FROM analytics_page_daily WHERE date = :d', [':d' => $day]);
}

$correct = daysAgo(40);
$short = daysAgo(39);
$surplus = daysAgo(38);
$botsOnly = daysAgo(37);
$never = daysAgo(36);
$broken = daysAgo(35);
$lastDeleted = daysAgo(31);
$firstKept = daysAgo(30);

function fixtures(): void
{
    global $correct, $short, $surplus, $botsOnly, $never, $broken, $lastDeleted, $firstKept;

    session('s1');
    session('s2');
    session('s3');
    session('bot', 1);
    foreach ([$correct, $short, $surplus, $never, $broken, $lastDeleted] as $day) {
        pageView($day, '10:00:00', P1, '/a', 's1');
        pageView($day, '11:00:00', P1, '/a?x', 's2');
        elementView($day, '10:00:00', J1, 'detail', C1, 's1');
    }
    pageView($short, '12:00:00', P1, '/a?y', 's3');
    pageView($lastDeleted, '23:59:00', P1, '/a', 's3');
    pageView($botsOnly, '10:00:00', P1, '/a', 'bot', 1);
    pageView($firstKept, '00:30:00', P1, '/a', 's1');

    foreach ([$correct, $short, $surplus, $broken, $lastDeleted] as $day) {
        aggregate($day);
    }
    // $short: stored before its third view arrived
    Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 2 WHERE date = :d', [':d' => $short])->execute();
    // $surplus: stored before late bot removal deleted raw rows
    Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 5 WHERE date = :d', [':d' => $surplus])->execute();
    // $broken: undercounted, and its repair will fail
    Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 1 WHERE date = :d', [':d' => $broken])->execute();
}

echo "Verify, repair, delete\n";
analyticsMysqlApp();
fixtures();
check('cleanup exits 0 when every day passes', 0, cleanup());
check('a correct day is deleted', 0, rawPages($correct));
check('a correct day keeps its aggregate', 2, storedPages($correct));
check('an undercounted day is repaired before deletion', 3, storedPages($short));
check('the repaired day is deleted', 0, rawPages($short));
check('stored above raw is left as it is', 5, storedPages($surplus));
check('a never aggregated day is aggregated, then deleted', [2, 0], [storedPages($never), rawPages($never)]);
check('a day with only bot traffic is not blocked', 1, rawPages($botsOnly));
check('the last day before the boundary is deleted whole', 0, rawPages($lastDeleted));
check('the first kept day is untouched, early hours included', 1, rawPages($firstKept));
check('visits were filled for the repaired never-aggregated day', 2, (int)scalar("SELECT unique_sessions FROM analytics_visits_daily WHERE date = :d AND source = 'pages' AND owner_uuid = ''", [':d' => $never]));
check('element views of deleted days are gone', 0, (int)scalar('SELECT COUNT(*) FROM analytics_element_views WHERE created_at < :c', [':c' => "$firstKept 00:00:00"]));

echo "\nA day that cannot be repaired is kept and reported\n";
analyticsMysqlApp();
fixtures();
check('cleanup exits non-zero', 1, cleanup([], NoRepairController::class, $broken));
check('the broken day keeps its raw data', 2, rawPages($broken));
check('other days are still deleted', 0, rawPages($correct));

echo "\nA verification exception keeps the day\n";
analyticsMysqlApp();
fixtures();
check('cleanup exits non-zero when verifying a day throws', 1, cleanup([], ThrowingRepairController::class, $broken));
check('the day whose verification threw keeps its raw data', 2, rawPages($broken));
check('its element views are kept too', 1, (int)scalar('SELECT COUNT(*) FROM analytics_element_views WHERE created_at >= :s AND created_at < :e', [':s' => "$broken 00:00:00", ':e' => date('Y-m-d', strtotime("$broken +1 day")) . ' 00:00:00']));
check('other days are still verified and deleted', [0, 3], [rawPages($correct), storedPages($short)]);

echo "\nCounts that cannot be read keep every day\n";
analyticsMysqlApp();
fixtures();
Yii::$app->db->createCommand('RENAME TABLE analytics_element_daily TO analytics_element_daily_gone')->execute();
check('cleanup exits non-zero when the counts cannot be read', 1, cleanup());
check('no raw data is deleted', [2, 3, 2], [rawPages($correct), rawPages($short), rawPages($broken)]);
Yii::$app->db->createCommand('RENAME TABLE analytics_element_daily_gone TO analytics_element_daily')->execute();

echo "\nDry run\n";
analyticsMysqlApp();
fixtures();
check('dry run exits 0', 0, cleanup(['dryRun' => '1']));
check('dry run deletes nothing', 3, rawPages($short));
check('dry run repairs nothing', 2, storedPages($short));

echo "\n--skipAggregationCheck\n";
analyticsMysqlApp();
fixtures();
check('skip exits 0', 0, cleanup(['skipAggregationCheck' => '1']));
check('skip deletes without repairing', [2, 0], [storedPages($short), rawPages($short)]);

echo "\nWithout the visits table\n";
analyticsMysqlApp(false);
fixtures();
check('cleanup still exits 0', 0, cleanup());
check('pages are still verified and repaired', 3, storedPages($short));

echo "\nSessions of a kept day survive, repeatedly\n";
analyticsMysqlApp();
$scanDay = daysAgo(35);
session('qr', 0, null, "$scanDay 09:00:00");
elementView($scanDay, '09:00:00', J1, 'scan', C1, 'qr', null, 'scan');
check('first run exits non-zero', 1, cleanup([], NoRepairController::class, $scanDay));
check('the scan element view is kept', 1, (int)scalar('SELECT COUNT(*) FROM analytics_element_views WHERE session_id = :s', [':s' => 'qr']));
check('the scan session is kept', 1, (int)scalar('SELECT COUNT(*) FROM analytics_sessions WHERE session_id = :s', [':s' => 'qr']));
check('second run exits non-zero too', 1, cleanup([], NoRepairController::class, $scanDay));
check('the scan element view is still kept', 1, (int)scalar('SELECT COUNT(*) FROM analytics_element_views WHERE session_id = :s', [':s' => 'qr']));
check('the scan session is still kept', 1, (int)scalar('SELECT COUNT(*) FROM analytics_sessions WHERE session_id = :s', [':s' => 'qr']));

echo "\nAn ancient bot page view is harmless\n";
analyticsMysqlApp();
session('bot', 1);
pageView(daysAgo(200), '10:00:00', P1, '/a', 'bot', 1);
session('s1');
pageView(daysAgo(40), '10:00:00', P1, '/a', 's1');
aggregate(daysAgo(40));
check('cleanup exits 0', 0, cleanup());
check('the bot row remains', 1, rawPages(daysAgo(200)));
check('the normal day is deleted', 0, rawPages(daysAgo(40)));

analyticsDone();
