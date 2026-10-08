<?php

/**
 * Suspected bots (is_bot = 2): committed from scores 50-69, excluded from every
 * statistic, kept by the bot deletion, sticky (a lower or missing score never
 * lowers them; only a score >= 70 raises them to bots), deleted by the cleanup
 * with normal retention.
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
const UA_CHROME_140 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

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

    public function volume(): array
    {
        $this->sessionScores = [];
        $this->scoreVolumeAnomalies();

        return $this->sessionScores;
    }

    public function deleteBots(): void
    {
        $this->deleteHighConfidenceBots();
    }

    public function summary(): void
    {
        $this->showDetectionSummary();
    }

    public function stdout($string)
    {
        return 0;
    }
}

/** Age scoring of one user agent throws, as a crafted one once did */
class ThrowingAgeController extends SuspectedController
{
    protected function getBrowserAgeScore($userAgent, ?\DeviceDetector\DeviceDetector $dd = null): int
    {
        if (str_contains((string)$userAgent, 'Boom')) {
            throw new \RuntimeException('crafted user agent');
        }

        return parent::getBrowserAgeScore($userAgent, $dd);
    }
}

function controller(string $class = SuspectedController::class): SuspectedController
{
    $controller = new $class('bot-detection', Yii::$app);
    $controller->interactive = false;
    $controller->color = false;
    $controller->today = '2026-10-08';
    $controller->batchSize = 2; // several chunks

    return $controller;
}

function botSession(string $id, int $isBot, string $createdAt, string $userAgent = UA_CURRENT, ?string $ip = null): void
{
    Yii::$app->db->createCommand()->insert('analytics_sessions', [
        'session_id' => $id, 'is_bot' => $isBot, 'user_agent' => $userAgent, 'created_at' => $createdAt, 'ip_address' => $ip,
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
botSession('susToBot', 2, $recent);
botSession('susLater', 2, $recent);
botSession('becameBot', 1, $recent);
botSession('becameBotSus', 1, $recent);
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
pageView($day, '10:00:00', P1, '/a', 'susToBot', 2);
pageView($day, '10:05:00', P1, '/b', 'susToBot', 1);
pageView($day, '10:00:00', P1, '/a', 'susLater', 2);
pageView($day, '10:05:00', P1, '/b', 'susLater'); // recorded after it became suspected
pageView($day, '10:00:00', P1, '/a', 'becameBot', 1);
pageView($day, '10:00:00', P1, '/a', 'becameBotSus', 1);
pageView($day, '10:00:00', P1, '/a', 'recorded', 1);

controller()->commitPrepared(['high' => 75, 'sus' => 60, 'low' => 40, 'lowWas2' => 40, 'flagged' => 60, 'susToBot' => 75, 'susLater' => 40, 'becameBot' => 40, 'becameBotSus' => 60]);

check('score 75 is a bot', 1, sessionBot('high'));
check('its page views are bots', [1, 1], pageViewBots('high'));
check('score 60 is suspected', 2, sessionBot('sus'));
check('its page views are suspected', [2, 2], pageViewBots('sus'));
check('score 40 is a visitor', 0, sessionBot('low'));
check('its page views stay counted', [0, 0], pageViewBots('low'));
check('a suspected session scoring 40 stays suspected', 2, sessionBot('lowWas2'));
check('its page views stay suspected', [2], pageViewBots('lowWas2'));
check('a suspected session scoring 75 becomes a bot', 1, sessionBot('susToBot'));
check('its page views become bots', [1, 1], pageViewBots('susToBot'));
check('a page view recorded after the session became suspected is synced to suspected', [2, 2], pageViewBots('susLater'));
check('a session that became a bot during the run is not lowered by a score of 40', [1, [1]], [sessionBot('becameBot'), pageViewBots('becameBot')]);
check('nor by a score of 60', [1, [1]], [sessionBot('becameBotSus'), pageViewBots('becameBotSus')]);
check('a suspected session keeps a page view flagged at recording', [1, 2], pageViewBots('flagged'));
check('score and reason are stored', ['60', 'test:60'], array_map('strval', array_values(rows("SELECT bot_score, bot_reason FROM analytics_sessions WHERE session_id = 'sus'")[0])));

echo "\nCommit: suspected sessions without a score stay suspected\n";
check('a suspected session with no score stays suspected', 2, sessionBot('stale'));
check('its page views stay suspected', [2, 2], pageViewBots('stale'));
check('an older suspected session stays suspected', 2, sessionBot('staleOld'));
check('and so do its page views', [2], pageViewBots('staleOld'));
check('a bot flagged at recording is untouched', 1, sessionBot('recorded'));
check('an unscored visitor is untouched', [0, [0, 0]], [sessionBot('clean'), pageViewBots('clean')]);

echo "\nScoring keeps evaluating suspected sessions\n";
analyticsMysqlApp();
botSession('oldSingle', 2, $recent, UA_CHROME_140);
pageView($day, '10:00:00', P1, '/a', 'oldSingle', 2);
botSession('oldSingleNew', 0, $recent, UA_CHROME_140);
pageView($day, '10:00:00', P1, '/a', 'oldSingleNew');
botSession('currentSingle', 2, $recent);
pageView($day, '10:00:00', P1, '/a', 'currentSingle', 2);
$scores = controller()->rescore();
check('a suspected session is scored again (outdated + single page + combo)', 65, $scores['oldSingle']['score'] ?? null);
check('and stays suspected', [2, [2]], [sessionBot('oldSingle'), pageViewBots('oldSingle')]);
check('a visitor with the same signals becomes suspected', [2, [2]], [sessionBot('oldSingleNew'), pageViewBots('oldSingleNew')]);
check('a suspected session whose signals weakened stays suspected', [20, 2, [2]], [$scores['currentSingle']['score'] ?? null, sessionBot('currentSingle'), pageViewBots('currentSingle')]);

echo "\nOne user agent whose age scoring throws does not abort the run\n";
analyticsMysqlApp();
botSession('boom', 0, $recent, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Boom/1 Chrome/154.0.0.0 Safari/537.36');
pageView($day, '10:00:00', P1, '/a', 'boom');
botSession('oldSingleAfter', 0, $recent, UA_CHROME_140);
pageView($day, '10:00:00', P1, '/a', 'oldSingleAfter');
try {
    $scores = controller(ThrowingAgeController::class)->rescore();
    $thrown = null;
} catch (\Throwable $e) {
    $scores = [];
    $thrown = get_class($e);
}
check('no exception escapes', null, $thrown);
check('the throwing session is scored without an age score', 20, $scores['boom']['score'] ?? null);
check('the next session is still scored and committed', [65, 2], [$scores['oldSingleAfter']['score'] ?? null, sessionBot('oldSingleAfter')]);

echo "\nIP volume scores only sessions inside the window\n";
analyticsMysqlApp();
$hourAgo = strtotime('-1 hour');
for ($i = 1; $i <= 11; $i++) {
    botSession("busy$i", 0, date('Y-m-d H:i:s', $hourAgo), UA_CURRENT, '203.0.113.9');
    pageView(date('Y-m-d', $hourAgo), date('H:i:s', $hourAgo), P1, '/a', "busy$i");
}
botSession('busyOld', 0, date('Y-m-d H:i:s', strtotime('-40 days')), UA_CURRENT, '203.0.113.9');
pageView(daysAgo(40), '10:00:00', P1, '/a', 'busyOld');
$scores = controller()->volume();
check('sessions of a busy IP are scored', 30, $scores['busy1']['score'] ?? null);
check('an old session of the same IP outside the window is not', false, isset($scores['busyOld']));

echo "\nBot deletion keeps suspected traffic\n";
analyticsMysqlApp();
botSession('bot', 1, $recent);
botSession('sus', 2, $recent);
botSession('human', 0, $recent);
botSession('flaggedLater', 1, $recent);
foreach (['bot' => 1, 'sus' => 2, 'human' => 0] as $id => $flag) {
    pageView($day, '10:00:00', P1, '/a', $id, $flag);
    elementView($day, '10:00:00', J1, 'list', C1, $id);
}
// flagged 1 at recording after two page views were recorded as 0
pageView($day, '10:00:00', P1, '/a', 'flaggedLater');
pageView($day, '10:05:00', P1, '/b', 'flaggedLater');
controller()->deleteBots();
check('a session flagged at recording is deleted with its earlier page views', [null, []], [sessionBot('flaggedLater'), pageViewBots('flaggedLater')]);
check('the bot session is deleted', null, sessionBot('bot'));
check('with its page and element views', [[], 0], [pageViewBots('bot'), (int)scalar("SELECT COUNT(*) FROM analytics_element_views WHERE session_id = 'bot'")]);
check('the suspected session is kept', 2, sessionBot('sus'));
check('with its page and element views', [[2], 1], [pageViewBots('sus'), (int)scalar("SELECT COUNT(*) FROM analytics_element_views WHERE session_id = 'sus'")]);
check('the visitor is kept', [0, [0]], [sessionBot('human'), pageViewBots('human')]);

echo "\nDemote, stats and summary\n";
botSession('demoted', 2, $recent);
pageView($day, '10:00:00', P1, '/a', 'demoted', 2);
pageView($day, '10:05:00', P1, '/b', 'demoted', 1);
check('demote exits 0', 0, controller()->runAction('demote', ['demoted']));
check('a demoted suspected session is a visitor', 0, sessionBot('demoted'));
check('its suspected page views are counted, recorded bots stay', [0, 1], pageViewBots('demoted'));
Yii::$app->db->createCommand()->delete('analytics_page_views', ['session_id' => 'demoted'])->execute();
Yii::$app->db->createCommand()->delete('analytics_sessions', ['session_id' => 'demoted'])->execute();
check('stats exits 0', 0, controller()->runAction('stats'));
controller()->summary();
check('summary runs', true, true);

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
