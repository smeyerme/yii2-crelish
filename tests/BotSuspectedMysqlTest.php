<?php

/**
 * Suspected bots (is_bot = 2): committed from scores 50-69, excluded from every
 * statistic, kept by the bot deletion, released when their score disappears,
 * deleted by the cleanup with normal retention.
 *
 * The commit and deletion steps are called directly on a scratch database
 * (the full bot-detection/index also loads remote datacenter and spam lists).
 * The user agent and single-page scoring steps run for real.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/BotSuspectedMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\commands\AnalyticsAggregationController;
use giantbits\crelish\commands\BotDetectionController;
use giantbits\crelish\components\Analytics\AggregationParts;

const P1 = 'a1000000-0000-4000-8000-000000000001';
const J1 = 'b1000000-0000-4000-8000-000000000001';
const C1 = 'c1000000-0000-4000-8000-000000000001';

const UA_CURRENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36';
const UA_CHROME_142 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';

class SuspectedController extends BotDetectionController
{
    /** @param array<string, int> $scores session_id => score */
    public function commitPrepared(array $scores): void
    {
        $this->sessionScores = [];
        foreach ($scores as $sessionId => $score) {
            $this->addScore($sessionId, $score, 'test:' . $score);
        }
        $this->commitScores();
    }

    /** Steps 3, 7, 9 and 10 of the nightly run */
    public function rescore(): array
    {
        $this->sessionScores = [];
        $this->scoreUserAgents();
        $this->scoreSinglePageSessions();
        $this->applyComboBoosts();
        $this->commitScores();

        return $this->sessionScores;
    }

    public function deleteBots(): void
    {
        $this->deleteHighConfidenceBots();
    }

    public function stdout($string)
    {
        return 0;
    }
}

function controller(): SuspectedController
{
    $controller = new SuspectedController('bot-detection', Yii::$app);
    $controller->interactive = false;
    $controller->color = false;
    $controller->today = '2026-10-08';
    $controller->batchSize = 2; // several chunks

    return $controller;
}

function botSession(string $id, int $isBot, string $createdAt, string $userAgent = UA_CURRENT): void
{
    Yii::$app->db->createCommand()->insert('analytics_sessions', [
        'session_id' => $id, 'is_bot' => $isBot, 'user_agent' => $userAgent, 'created_at' => $createdAt,
    ])->execute();
}

function sessionBot(string $id): ?int
{
    $value = scalar('SELECT is_bot FROM analytics_sessions WHERE session_id = :id', [':id' => $id]);

    return $value === false ? null : (int)$value;
}

/** is_bot of the session's page views, in insertion order */
function pageViewBots(string $id): array
{
    return array_map('intval', array_column(
        rows('SELECT is_bot FROM analytics_page_views WHERE session_id = :id ORDER BY id', [':id' => $id]),
        'is_bot'
    ));
}

$recent = date('Y-m-d H:i:s', strtotime('-1 day'));
$day = daysAgo(1);

echo "Commit: three states\n";
analyticsMysqlApp();
botSession('high', 0, $recent);
botSession('sus', 0, $recent);
botSession('low', 0, $recent);
botSession('lowWas2', 2, $recent);
botSession('flagged', 0, $recent);
botSession('stale', 2, $recent);
botSession('staleOld', 2, date('Y-m-d H:i:s', strtotime('-40 days')));
botSession('recorded', 1, $recent);
botSession('clean', 0, $recent);
foreach (['high', 'sus', 'low', 'clean'] as $id) {
    pageView($day, '10:00:00', P1, '/a', $id);
    pageView($day, '10:05:00', P1, '/b', $id);
}
pageView($day, '10:00:00', P1, '/a', 'lowWas2', 2);
pageView($day, '10:00:00', P1, '/a', 'flagged', 1);
pageView($day, '10:05:00', P1, '/b', 'flagged');
pageView($day, '10:00:00', P1, '/a', 'stale', 2);
pageView($day, '10:05:00', P1, '/b', 'stale', 2);
pageView(daysAgo(40), '10:00:00', P1, '/a', 'staleOld', 2);
pageView($day, '10:00:00', P1, '/a', 'recorded', 1);

controller()->commitPrepared(['high' => 75, 'sus' => 60, 'low' => 40, 'lowWas2' => 40, 'flagged' => 60]);

check('score 75 is a bot', 1, sessionBot('high'));
check('its page views are bots', [1, 1], pageViewBots('high'));
check('score 60 is suspected', 2, sessionBot('sus'));
check('its page views are suspected', [2, 2], pageViewBots('sus'));
check('score 40 is a visitor', 0, sessionBot('low'));
check('its page views stay counted', [0, 0], pageViewBots('low'));
check('a suspected session scoring 40 is a visitor again', 0, sessionBot('lowWas2'));
check('its page views are counted again', [0], pageViewBots('lowWas2'));
check('a suspected session keeps a page view flagged at recording', [1, 2], pageViewBots('flagged'));
check('score and reason are stored', ['60', 'test:60'], array_map('strval', array_values(rows("SELECT bot_score, bot_reason FROM analytics_sessions WHERE session_id = 'sus'")[0])));

echo "\nCommit: suspected sessions without a score are released\n";
check('a suspected session with no score is a visitor again', 0, sessionBot('stale'));
check('its page views are counted again', [0, 0], pageViewBots('stale'));
check('outside the scoring window it stays suspected', 2, sessionBot('staleOld'));
check('and so do its page views', [2], pageViewBots('staleOld'));
check('a bot flagged at recording is untouched', 1, sessionBot('recorded'));
check('an unscored visitor is untouched', [0, [0, 0]], [sessionBot('clean'), pageViewBots('clean')]);

echo "\nScoring keeps evaluating suspected sessions\n";
analyticsMysqlApp();
botSession('oldSingle', 2, $recent, UA_CHROME_142);
pageView($day, '10:00:00', P1, '/a', 'oldSingle', 2);
botSession('oldSingleNew', 0, $recent, UA_CHROME_142);
pageView($day, '10:00:00', P1, '/a', 'oldSingleNew');
botSession('currentSingle', 2, $recent);
pageView($day, '10:00:00', P1, '/a', 'currentSingle', 2);
$scores = controller()->rescore();
check('a suspected session is scored again (outdated + single page + combo)', 65, $scores['oldSingle']['score'] ?? null);
check('and stays suspected', [2, [2]], [sessionBot('oldSingle'), pageViewBots('oldSingle')]);
check('a visitor with the same signals becomes suspected', [2, [2]], [sessionBot('oldSingleNew'), pageViewBots('oldSingleNew')]);
check('a suspected session whose signals weakened is a visitor again', [20, 0, [0]], [$scores['currentSingle']['score'] ?? null, sessionBot('currentSingle'), pageViewBots('currentSingle')]);

echo "\nBot deletion keeps suspected traffic\n";
analyticsMysqlApp();
botSession('bot', 1, $recent);
botSession('sus', 2, $recent);
botSession('human', 0, $recent);
foreach (['bot' => 1, 'sus' => 2, 'human' => 0] as $id => $flag) {
    pageView($day, '10:00:00', P1, '/a', $id, $flag);
    elementView($day, '10:00:00', J1, 'list', C1, $id);
}
controller()->deleteBots();
check('the bot session is deleted', null, sessionBot('bot'));
check('with its page and element views', [[], 0], [pageViewBots('bot'), (int)scalar("SELECT COUNT(*) FROM analytics_element_views WHERE session_id = 'bot'")]);
check('the suspected session is kept', 2, sessionBot('sus'));
check('with its page and element views', [[2], 1], [pageViewBots('sus'), (int)scalar("SELECT COUNT(*) FROM analytics_element_views WHERE session_id = 'sus'")]);
check('the visitor is kept', [0, [0]], [sessionBot('human'), pageViewBots('human')]);

echo "\nAggregation counts neither bots nor suspected\n";
// rows still present from the previous block, plus a bot that was not yet deleted
botSession('bot2', 1, $recent);
pageView($day, '11:00:00', P1, '/a', 'bot2', 1);
elementView($day, '11:00:00', J1, 'list', C1, 'bot2');
(new AnalyticsAggregationController('analytics-aggregation', Yii::$app))->aggregateDate($day, AggregationParts::ALL);
check('page aggregate counts the visitor only', 1, (int)scalar('SELECT SUM(total_views) FROM analytics_page_daily WHERE date = :d', [':d' => $day]));
check('element aggregate counts the visitor only', 1, (int)scalar("SELECT SUM(total_views) FROM analytics_element_daily WHERE date = :d AND event_type = 'list'", [':d' => $day]));
check('page visits count the visitor only', 1, (int)scalar("SELECT unique_sessions FROM analytics_visits_daily WHERE date = :d AND source = 'pages' AND owner_uuid = '' AND event_type = ''", [':d' => $day]));
check('element visits count the visitor only', 1, (int)scalar("SELECT unique_sessions FROM analytics_visits_daily WHERE date = :d AND source = 'elements' AND owner_uuid = '' AND event_type = ''", [':d' => $day]));

echo "\nCleanup deletes suspected page views with normal retention\n";
analyticsMysqlApp();
$old = daysAgo(40);
$kept = daysAgo(5);
$suspectedOnly = daysAgo(45);
botSession('sus', 2, "$suspectedOnly 09:00:00");
botSession('human', 0, "$old 09:00:00");
pageView($suspectedOnly, '10:00:00', P1, '/a', 'sus', 2);
pageView($old, '10:00:00', P1, '/a', 'sus', 2);
pageView($old, '10:05:00', P1, '/a', 'human');
pageView($kept, '10:00:00', P1, '/a', 'sus', 2);
pageView($kept, '10:05:00', P1, '/a', 'human');
foreach ([$suspectedOnly, $old, $kept] as $d) {
    (new AnalyticsAggregationController('analytics-aggregation', Yii::$app))->aggregateDate($d, AggregationParts::ALL);
}
$cleanup = new AnalyticsAggregationController('analytics-aggregation', Yii::$app);
$cleanup->interactive = false;
$cleanup->color = false;
ob_start();
$exit = $cleanup->runAction('cleanup', ['retentionDays' => '30', 'force' => '1']);
ob_end_clean();
$pagesOn = static fn(string $d): int => (int)scalar('SELECT COUNT(*) FROM analytics_page_views WHERE DATE(created_at) = :d', [':d' => $d]);
check('cleanup exits 0', 0, $exit);
check('suspected page views of an old day are deleted', 0, $pagesOn($old));
check('so is a day holding only suspected page views', 0, $pagesOn($suspectedOnly));
check('suspected page views of a kept day stay', 2, $pagesOn($kept));

analyticsDone();
