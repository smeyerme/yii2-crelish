<?php

/**
 * Which days the cleanup may delete, and when a day's aggregates are short.
 *
 * Run with:  php tests/AnalyticsRetentionTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/bootstrap.php';

use giantbits\crelish\components\Analytics\AnalyticsDayCheck;
use giantbits\crelish\components\Analytics\AnalyticsRetention;

echo "Retention boundary\n";
check('first kept day is today minus retention', '2026-09-08', AnalyticsRetention::firstKeptDay('2026-10-08', 30));
check('across a year boundary', '2025-12-02', AnalyticsRetention::firstKeptDay('2026-01-01', 30));
checkThrows('retention below one day is refused', fn() => AnalyticsRetention::firstKeptDay('2026-10-08', 0), \InvalidArgumentException::class);

echo "\nDays to delete\n";
check('no raw data', [], AnalyticsRetention::daysToDelete(null, '2026-09-08'));
check('whole days before the first kept day', ['2026-09-05', '2026-09-06', '2026-09-07'], AnalyticsRetention::daysToDelete('2026-09-05', '2026-09-08'));
check('a timestamp counts as its day', ['2026-09-07'], AnalyticsRetention::daysToDelete('2026-09-07 23:59:59', '2026-09-08'));
check('nothing when the oldest day is kept', [], AnalyticsRetention::daysToDelete('2026-09-08', '2026-09-08'));

echo "\nDay ranges\n";
check('a day is half-open midnight to midnight', ['2026-02-28 00:00:00', '2026-03-01 00:00:00'], AnalyticsRetention::dayRange('2026-02-28'));
check('the DST day still ends at the next midnight', ['2026-03-29 00:00:00', '2026-03-30 00:00:00'], AnalyticsRetention::dayRange('2026-03-29'));
check('consecutive days merge, gaps split', [
    ['2026-09-01 00:00:00', '2026-09-03 00:00:00'],
    ['2026-09-04 00:00:00', '2026-09-05 00:00:00'],
], AnalyticsRetention::ranges(['2026-09-04', '2026-09-01', '2026-09-02']));
check('no days, no ranges', [], AnalyticsRetention::ranges([]));

echo "\nShortfalls\n";
check('stored equal to raw passes', [], AnalyticsDayCheck::shortfalls(['page_views' => 5], ['page_views' => 5]));
check('stored above raw passes (bots removed later)', [], AnalyticsDayCheck::shortfalls(['page_views' => 9], ['page_views' => 5]));
check('stored below raw is short', ['page_views'], AnalyticsDayCheck::shortfalls(['page_views' => 4], ['page_views' => 5]));
check('no stored row while raw has traffic is short', ['element_views'], AnalyticsDayCheck::shortfalls([], ['element_views' => 3]));
check('nothing reportable passes', [], AnalyticsDayCheck::shortfalls([], ['page_views' => 0, 'element_views' => 0]));
check('stored metrics without raw counterpart are ignored', [], AnalyticsDayCheck::shortfalls(['page_visits' => 1], []));
check('several shortfalls in raw order', ['page_views', 'page_visits'], AnalyticsDayCheck::shortfalls(
    ['page_views' => 1, 'page_visits' => 0, 'element_views' => 9],
    ['page_views' => 2, 'page_visits' => 1, 'element_views' => 9]
));

analyticsDone();
