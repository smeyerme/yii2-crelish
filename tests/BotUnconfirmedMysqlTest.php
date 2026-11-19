<?php

/**
 * Browser confirmation as a bot rule (browser_confirmation_since): a session no
 * browser confirmed becomes suspected, never a deleted bot by itself; confirmed
 * sessions, sessions from before the start and sessions still inside their
 * grace minutes are left alone; without the setting nothing changes.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/BotUnconfirmedMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\commands\BotDetectionController;

const P1 = 'a1000000-0000-4000-8000-000000000001';
const UA_CURRENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36';
const UA_CHROME_100 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.0.0 Safari/537.36';

class UnconfirmedController extends BotDetectionController
{
    public function settings(array $thresholds): void
    {
        $this->thresholds = array_merge($this->thresholds, $thresholds);
    }

    /** Steps 3, 7, 9, 10, 11 and 12 of the nightly run (no remote lists) */
    public function rescore(): array
    {
        $this->sessionScores = [];
        $this->scoreUserAgents();
        $this->scoreSinglePageSessions();
        $this->scoreUnconfirmedSessions();
        $this->applyComboBoosts();
        $this->capUnconfirmedScores();
        $this->commitScores();
        $this->deleteHighConfidenceBots();

        return $this->sessionScores;
    }

    public function stdout($string)
    {
        return 0;
    }
}

function unconfirmedController(array $thresholds): UnconfirmedController
{
    $controller = new UnconfirmedController('bot-detection', Yii::$app);
    $controller->interactive = false;
    $controller->color = false;
    $controller->today = date('Y-m-d');
    $controller->batchSize = 2; // several batches
    $controller->settings($thresholds);

    return $controller;
}

function unconfirmedSession(string $id, string $createdAt, ?string $confirmedAt, int $pages = 2, string $userAgent = UA_CURRENT, int $isBot = 0): void
{
    Yii::$app->db->createCommand()->insert('analytics_sessions', [
        'session_id' => $id, 'is_bot' => $isBot, 'user_agent' => $userAgent,
        'created_at' => $createdAt, 'confirmed_at' => $confirmedAt, 'total_pages' => $pages,
    ])->execute();
    $day = substr($createdAt, 0, 10);
    for ($i = 0; $i < $pages; $i++) {
        pageView($day, sprintf('10:%02d:00', $i * 5), P1, '/p' . $i, $id, $isBot);
    }
}

function state(string $id): ?int
{
    $value = scalar('SELECT is_bot FROM analytics_sessions WHERE session_id = :id', [':id' => $id]);

    return $value === false ? null : (int)$value;
}

function viewStates(string $id): array
{
    return array_map('intval', array_column(
        rows('SELECT is_bot FROM analytics_page_views WHERE session_id = :id ORDER BY id', [':id' => $id]),
        'is_bot'
    ));
}

function setUp(): void
{
    analyticsMysqlApp();
    Yii::$app->db->createCommand('ALTER TABLE analytics_sessions ADD confirmed_at timestamp NULL DEFAULT NULL')->execute();
    Yii::$app->db->getSchema()->refreshTableSchema('analytics_sessions');

    $yesterday = date('Y-m-d', strtotime('-1 day')) . ' 10:00:00';
    $confirmed = date('Y-m-d', strtotime('-1 day')) . ' 10:00:02';
    unconfirmedSession('person', $yesterday, $confirmed);
    unconfirmedSession('personSingle', $yesterday, $confirmed, 1);
    unconfirmedSession('scraper', $yesterday, null);
    unconfirmedSession('scraperSingle', $yesterday, null, 1);
    unconfirmedSession('scraperOld', $yesterday, null, 1, UA_CHROME_100);
    unconfirmedSession('beforeStart', date('Y-m-d', strtotime('-20 days')) . ' 10:00:00', null);
    unconfirmedSession('justNow', date('Y-m-d H:i:s'), null);
    // Sessions are stamped by the database clock (NOW()), which the grace minutes use too
    Yii::$app->db->createCommand("UPDATE analytics_sessions SET created_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE session_id = 'justNow'")->execute();
}

echo "Rule off (no browser_confirmation_since)\n";
setUp();
unconfirmedController([])->rescore();
check('an unconfirmed session stays a visitor', 0, state('scraper'));
check('an unconfirmed single page stays a visitor', 0, state('scraperSingle'));

echo "\nRule on\n";
setUp();
$since = date('Y-m-d H:i:s', strtotime('-10 days'));
$scores = unconfirmedController(['browser_confirmation_since' => $since])->rescore();
check('a confirmed session stays a visitor', [0, [0, 0]], [state('person'), viewStates('person')]);
check('a confirmed single page stays a visitor', 0, state('personSingle'));
check('an unconfirmed session becomes suspected', [2, [2, 2]], [state('scraper'), viewStates('scraper')]);
check('with its score and reason', [50, 'browser_unconfirmed'], array_map(
    static fn($v) => is_numeric($v) ? (int)$v : $v,
    array_values(rows("SELECT bot_score, bot_reason FROM analytics_sessions WHERE session_id = 'scraper'")[0])
));
check('an unconfirmed single page (50 + 20) becomes suspected, not a bot', [2, 70, 69], [state('scraperSingle'), 70, $scores['scraperSingle']['score'] ?? null]);
check('its raw data is kept', [2], viewStates('scraperSingle'));
check('an unconfirmed outdated single page that is a bot without the rule is still deleted', null, state('scraperOld'));
check('a session from before the start is not judged', 0, state('beforeStart'));
check('a session inside its grace minutes is not judged yet', 0, state('justNow'));

echo "\nRule with a broken date\n";
setUp();
unconfirmedController(['browser_confirmation_since' => 'soon'])->rescore();
check('nothing is judged', 0, state('scraper'));

analyticsDone();
