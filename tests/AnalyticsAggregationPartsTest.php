<?php

/**
 * --only / --pagesOnly selection of aggregation parts.
 *
 * Run with:  php tests/AnalyticsAggregationPartsTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/bootstrap.php';

use giantbits\crelish\components\Analytics\AggregationParts;

echo "Selection\n";
check('nothing given means all parts', ['pages', 'elements', 'visits'], AggregationParts::resolve(null));
check('empty string means all parts', ['pages', 'elements', 'visits'], AggregationParts::resolve(''));
check('one part', ['visits'], AggregationParts::resolve('visits'));
check('canonical order, spaces and case ignored', ['pages', 'visits'], AggregationParts::resolve(' Visits , pages '));
check('duplicates collapse', ['elements'], AggregationParts::resolve('elements,elements'));
checkThrows('unknown part is rejected', fn() => AggregationParts::resolve('pages,sessions'), \InvalidArgumentException::class);

echo "\n--pagesOnly alias (0.24.2)\n";
check('pagesOnly alone', ['pages'], AggregationParts::resolve(null, true));
check('pagesOnly with matching --only', ['pages'], AggregationParts::resolve('pages', true));
checkThrows('pagesOnly with a different --only', fn() => AggregationParts::resolve('visits', true), \InvalidArgumentException::class);

analyticsDone();
