<?php

/**
 * Reading visits: totals, per day, per month, coverage of a period.
 *
 * Run with:  php tests/AnalyticsVisitsReaderTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/sqlite.php';

use giantbits\crelish\components\Analytics\VisitsReader;

const C1 = 'c1000000-0000-4000-8000-000000000001';

analyticsSqliteApp();
visitRow('2026-09-08', 'pages', '', '', 10, 1);
visitRow('2026-09-09', 'pages', '', '', 20, 2);
visitRow('2026-10-01', 'pages', '', '', 5, 0);
visitRow('2026-09-09', 'elements', '', '', 99);
visitRow('2026-09-09', 'elements', C1, '', 7);
visitRow('2026-09-09', 'elements', C1, 'detail', 4);

$reader = new VisitsReader();

echo "Totals\n";
check('site page visits over a covered period', ['available' => true, 'since' => null, 'visits' => 30, 'users' => 3],
    $reader->summary('pages', '', '2026-09-08', '2026-09-30'));
check('only the any-event row counts for an owner', 7, $reader->summary('elements', C1, '2026-09-01', '2026-09-30')['visits']);
check('a period before the first recorded day says from when', '2026-09-08', $reader->summary('pages', '', '2026-08-01', '2026-09-30')['since']);
check('nothing in range gives zero, not null', 0, $reader->summary('pages', '', '2026-09-20', '2026-09-25')['visits']);

echo "\nPer day and per month\n";
check('by day', ['2026-09-08' => ['visits' => 10, 'users' => 1], '2026-09-09' => ['visits' => 20, 'users' => 2]],
    $reader->byDay('pages', '', '2026-09-01', '2026-09-30'));
check('by month', ['2026-09' => ['visits' => 30, 'users' => 3], '2026-10' => ['visits' => 5, 'users' => 0]],
    $reader->byMonth('pages', '', '2026-09-01', '2026-10-31'));

echo "\nWithout the table\n";
analyticsSqliteApp(false);
$reader = new VisitsReader();
check('not available', false, $reader->available());
check('summary says so and has no figure', ['available' => false, 'since' => null, 'visits' => null, 'users' => null],
    $reader->summary('pages', '', '2026-09-01', '2026-09-30'));
check('by day is empty', [], $reader->byDay('pages', '', '2026-09-01', '2026-09-30'));

analyticsDone();
