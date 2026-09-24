<?php

/**
 * Short link statistics: aggregated days plus raw events after the last
 * aggregation, bot filtering, no double counting; title lookup.
 *
 * Run with:  php tests/ShortLinkStatsTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\ElementTitleResolver;
use giantbits\crelish\components\shortlinks\ShortLinkStats;
use giantbits\crelish\models\ShortLink;

const A = 'd0000000-0000-4000-8000-00000000000a';
const B = 'd0000000-0000-4000-8000-00000000000b';

function day(int $ago): string
{
    return date('Y-m-d', strtotime("-{$ago} days"));
}

function daily(string $uuid, string $date, string $type, int $views, int $sessions, string $elementType = 'shortlink'): void
{
    Yii::$app->db->createCommand()->insert('analytics_element_daily', [
        'date' => $date, 'element_uuid' => $uuid, 'element_type' => $elementType, 'page_uuid' => $uuid,
        'event_type' => $type, 'total_views' => $views, 'unique_sessions' => $sessions,
    ])->execute();
}

function raw(string $uuid, string $date, string $type, string $session, int $isBot = 0, string $elementType = 'shortlink'): void
{
    $db = Yii::$app->db;
    if (!(new yii\db\Query())->from('analytics_sessions')->where(['session_id' => $session])->exists()) {
        $db->createCommand()->insert('analytics_sessions', ['session_id' => $session, 'is_bot' => $isBot])->execute();
    }
    $db->createCommand()->insert('analytics_element_views', [
        'element_uuid' => $uuid, 'element_type' => $elementType, 'page_uuid' => $uuid,
        'session_id' => $session, 'type' => $type, 'created_at' => $date . ' 10:00:00',
    ])->execute();
}

shortLinkApp();

// Aggregated up to two days ago
daily(A, day(4), 'scan', 5, 4);
daily(A, day(2), 'scan', 3, 3);
daily(A, day(2), 'click', 2, 2);
daily(B, day(2), 'scan', 7, 7);
daily(A, day(2), 'view', 99, 99, 'news');
// Raw rows from an already aggregated day must not be counted twice
raw(A, day(2), 'scan', 's-old');
// Raw rows after the last aggregation
raw(A, day(1), 'scan', 's1');
raw(A, day(1), 'scan', 's1');
raw(A, day(0), 'click', 's2');
raw(A, day(0), 'fallback', 's3');
raw(A, day(0), 'scan', 'bot', 1);
raw(A, day(0), 'click', 's4', 0, 'news');

$stats = new ShortLinkStats();
check('last aggregated date', day(2), $stats->lastAggregatedDate());

echo "Per link\n";
$result = $stats->forLink(A, day(6), day(0));
check('totals combine aggregated and raw data', ['scan' => 10, 'click' => 3, 'fallback' => 1], $result['totals']);
check('unique sessions are summed per day', 4 + 3 + 2 + 1 + 1 + 1, $result['uniqueSessions']);
check('every day in the range is present', 7, count($result['days']));
check('empty days are zero', ['scan' => 0, 'click' => 0, 'fallback' => 0], $result['days'][day(6)]);
check('raw day counts', ['scan' => 2, 'click' => 0, 'fallback' => 0], $result['days'][day(1)]);
check('bots are excluded from raw data', 0, $result['days'][day(0)]['scan']);

echo "\nSummaries\n";
$summaries = $stats->summaries([A, B], 30);
check('summary for A', [10, 3, 1], [$summaries[A]['scan'], $summaries[A]['click'], $summaries[A]['fallback']]);
check('summary for B', [7, 0, 0], [$summaries[B]['scan'], $summaries[B]['click'], $summaries[B]['fallback']]);
check('last hit ignores bots', day(0) . ' 10:00:00', $summaries[A]['last']);
check('no raw hits means no last hit', null, $summaries[B]['last']);
check('empty uuid list', [], $stats->summaries([]));

echo "\nNever aggregated\n";
shortLinkApp();
raw(A, day(0), 'scan', 's1');
check('raw data alone is used', 1, (new ShortLinkStats())->forLink(A, day(1), day(0))['totals']['scan']);

echo "\nTitle lookup\n";
$link = new ShortLink();
$link->setAttributes(['systitle' => 'IHF 2026 Flyer', 'code' => 'ihf26', 'state' => 2, 'target_type' => 'url', 'target_url' => 'https://www.example.com/'], false);
$link->save(false);
ElementTitleResolver::clearCache();
check('analytics widgets can name a short link', 'IHF 2026 Flyer', ElementTitleResolver::resolve($link->uuid, 'shortlink'));

shortLinkDone();
