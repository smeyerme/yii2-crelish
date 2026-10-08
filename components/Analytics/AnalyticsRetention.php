<?php

namespace giantbits\crelish\components\Analytics;

/**
 * Which whole days of raw analytics data are due for deletion.
 *
 * Deleting by a timestamp ("now minus 30 days") cut one day in two every night:
 * its early hours were gone while the rest stayed, a state that can neither be
 * verified nor repaired. Days are therefore always handled whole.
 */
final class AnalyticsRetention
{
    /**
     * The oldest day whose raw data is kept; every earlier day may be deleted.
     */
    public static function firstKeptDay(string $today, int $retentionDays): string
    {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('retentionDays must be at least 1');
        }

        return (new \DateTimeImmutable(substr($today, 0, 10)))->modify("-{$retentionDays} days")->format('Y-m-d');
    }

    /**
     * Days from the oldest raw day up to, not including, the first kept day.
     *
     * @param string|null $oldestRawDay Y-m-d or a timestamp; null when there is no raw data
     * @return string[] Y-m-d, ascending
     */
    public static function daysToDelete(?string $oldestRawDay, string $firstKeptDay): array
    {
        if ($oldestRawDay === null || $oldestRawDay === '') {
            return [];
        }

        $days = [];
        $day = new \DateTimeImmutable(substr($oldestRawDay, 0, 10));
        $end = new \DateTimeImmutable($firstKeptDay);

        for (; $day < $end; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /**
     * @return array{0: string, 1: string} Half-open [start, end) of a day
     */
    public static function dayRange(string $date): array
    {
        $day = new \DateTimeImmutable(substr($date, 0, 10));

        return [$day->format('Y-m-d') . ' 00:00:00', $day->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
    }

    /**
     * Merge days into half-open ranges of consecutive days, so deleting them
     * takes one statement per range instead of one per day.
     *
     * @param string[] $days Y-m-d in any order
     * @return array<int, array{0: string, 1: string}>
     */
    public static function ranges(array $days): array
    {
        $days = array_values(array_unique($days));
        sort($days);

        $ranges = [];
        foreach ($days as $day) {
            [$start, $end] = self::dayRange($day);
            $last = count($ranges) - 1;

            if ($last >= 0 && $ranges[$last][1] === $start) {
                $ranges[$last][1] = $end;
            } else {
                $ranges[] = [$start, $end];
            }
        }

        return $ranges;
    }
}
