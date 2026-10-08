<?php

namespace giantbits\crelish\components\Analytics;

/**
 * Whether a day's stored aggregates cover its raw data.
 *
 * Stored may exceed raw: bot detection re-scores the last 30 days and deletes
 * raw rows of sessions found to be bots after the day was aggregated. Stored
 * numbers are deliberately not lowered to follow; only an undercount (stored
 * below raw) means traffic would be lost by deleting the raw data.
 */
final class AnalyticsDayCheck
{
    /**
     * @param array<string, int> $stored Metric => stored count (missing = no aggregate row)
     * @param array<string, int> $raw Metric => count in the raw data
     * @return string[] Metrics whose stored count is below the raw count, in the order of $raw
     */
    public static function shortfalls(array $stored, array $raw): array
    {
        $short = [];
        foreach ($raw as $metric => $count) {
            if ((int)($stored[$metric] ?? 0) < (int)$count) {
                $short[] = $metric;
            }
        }

        return $short;
    }
}
