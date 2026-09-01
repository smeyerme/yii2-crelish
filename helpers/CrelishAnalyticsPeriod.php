<?php

namespace giantbits\crelish\helpers;

use DateTime;
use Yii;

/**
 * Resolves analytics reporting periods into concrete date ranges.
 *
 * Handles both the named presets ("month", "quarter", ...) and the free
 * custom range submitted by the date range picker as
 * `period=custom&start_date=Y-m-d&end_date=Y-m-d`.
 */
class CrelishAnalyticsPeriod
{
    /**
     * Custom range period key.
     */
    public const CUSTOM = 'custom';

    /**
     * Period key used whenever the request carries nothing usable.
     */
    public const FALLBACK = 'month';

    /**
     * Legacy period keys kept working so older bookmarks and links do not
     * silently fall back to the default period.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'day' => 'today',
    ];

    /**
     * Earliest date any range may start at. Analytics predating this do not exist.
     */
    public const FLOOR = '2000-01-01';

    /**
     * Display format for custom ranges and picker input values.
     */
    public const DISPLAY_FORMAT = 'd.m.Y';

    /**
     * Longest range still grouped by day. Set above the 'quarter' preset (91
     * inclusive days) so that preset keeps its historic daily granularity.
     */
    private const DAILY_MAX_DAYS = 120;

    /**
     * Named presets, in the order they should appear in the picker.
     *
     * @return array<string, string> period key => translated label
     */
    public static function presets(): array
    {
        return [
            'today' => Yii::t('crelish', 'Today'),
            'yesterday' => Yii::t('crelish', 'Yesterday'),
            'week' => Yii::t('crelish', 'Last 7 Days'),
            'month' => Yii::t('crelish', 'Last 30 Days'),
            'previous_month' => Yii::t('crelish', 'Previous Month'),
            'quarter' => Yii::t('crelish', 'Last 90 Days'),
            'year' => Yii::t('crelish', 'Last Year'),
            'all' => Yii::t('crelish', 'All Time'),
        ];
    }

    /**
     * Resolve a period into a concrete date range.
     *
     * Invalid input degrades to the default preset rather than throwing, so a
     * tampered query string cannot break a dashboard.
     *
     * @param string|null $period Period key, or self::CUSTOM
     * @param string|null $start Y-m-d, only read for custom ranges
     * @param string|null $end Y-m-d, only read for custom ranges
     * @return array{0: string, 1: string, 2: string} [startDate, endDate, normalizedPeriod]
     */
    public static function resolve(?string $period, ?string $start = null, ?string $end = null): array
    {
        $period = $period !== null ? trim($period) : '';

        if ($period === self::CUSTOM) {
            $range = self::resolveCustom($start, $end);

            if ($range !== null) {
                return [$range[0], $range[1], self::CUSTOM];
            }

            $period = self::FALLBACK;
        }

        $period = self::ALIASES[$period] ?? $period;

        if (!array_key_exists($period, self::presets())) {
            $period = self::FALLBACK;
        }

        [$startDate, $endDate] = self::presetDates($period);

        return [$startDate, $endDate, $period];
    }

    /**
     * Resolve only the start date of a period.
     *
     * @param string|null $period
     * @param string|null $start
     * @param string|null $end
     * @return string Y-m-d
     */
    public static function startDate(?string $period, ?string $start = null, ?string $end = null): string
    {
        return self::resolve($period, $start, $end)[0];
    }

    /**
     * Human readable label for a resolved period.
     *
     * @param string $period
     * @param string|null $start Y-m-d
     * @param string|null $end Y-m-d
     * @return string
     */
    public static function label(string $period, ?string $start = null, ?string $end = null): string
    {
        if ($period === self::CUSTOM) {
            $range = self::resolveCustom($start, $end);

            if ($range !== null) {
                return self::formatDisplay($range[0]) . ' – ' . self::formatDisplay($range[1]);
            }

            $period = self::FALLBACK;
        }

        $period = self::ALIASES[$period] ?? $period;
        $presets = self::presets();

        return $presets[$period] ?? $presets[self::FALLBACK];
    }

    /**
     * Pick a sensible SQL grouping granularity for a date range.
     *
     * Preset periods keep their historic granularity; custom ranges derive it
     * from the range length so a multi-year range does not render thousands of
     * daily data points.
     *
     * @param string $startDate Y-m-d
     * @param string $endDate Y-m-d
     * @return string one of 'hour', 'day', 'month'
     */
    public static function granularity(string $startDate, string $endDate): string
    {
        $days = self::lengthInDays($startDate, $endDate);

        if ($days <= 2) {
            return 'hour';
        }

        if ($days <= self::DAILY_MAX_DAYS) {
            return 'day';
        }

        return 'month';
    }

    /**
     * Number of days covered by a range, inclusive of both ends.
     *
     * @param string $startDate Y-m-d
     * @param string $endDate Y-m-d
     * @return int
     */
    public static function lengthInDays(string $startDate, string $endDate): int
    {
        $start = self::parse($startDate);
        $end = self::parse($endDate);

        if ($start === null || $end === null) {
            return 0;
        }

        return (int)$start->diff($end)->days + 1;
    }

    /**
     * Format a Y-m-d date for display.
     *
     * @param string $date
     * @return string
     */
    public static function formatDisplay(string $date): string
    {
        $parsed = self::parse($date);

        return $parsed !== null ? $parsed->format(self::DISPLAY_FORMAT) : $date;
    }

    /**
     * Date range for a named preset.
     *
     * @param string $period
     * @return array{0: string, 1: string}
     */
    private static function presetDates(string $period): array
    {
        $today = date('Y-m-d');

        switch ($period) {
            case 'today':
                return [$today, $today];
            case 'yesterday':
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                return [$yesterday, $yesterday];
            case 'week':
                return [date('Y-m-d', strtotime('-7 days')), $today];
            case 'previous_month':
                return [date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('-31 days'))];
            case 'quarter':
                return [date('Y-m-d', strtotime('-90 days')), $today];
            case 'year':
                return [date('Y-m-d', strtotime('-365 days')), $today];
            case 'all':
                return [self::FLOOR, $today];
            case 'month':
            default:
                return [date('Y-m-d', strtotime('-30 days')), $today];
        }
    }

    /**
     * Validate and normalise a custom range.
     *
     * Reversed ranges are swapped, the start is clamped to the floor and the
     * end to today.
     *
     * @param string|null $start
     * @param string|null $end
     * @return array{0: string, 1: string}|null null when the input is unusable
     */
    private static function resolveCustom(?string $start, ?string $end): ?array
    {
        $startDate = self::parse($start);
        $endDate = self::parse($end);

        if ($startDate === null || $endDate === null) {
            return null;
        }

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $floor = self::parse(self::FLOOR);
        $today = self::parse(date('Y-m-d'));

        if ($startDate < $floor) {
            $startDate = $floor;
        }

        if ($endDate > $today) {
            $endDate = $today;
        }

        if ($startDate > $endDate) {
            return null;
        }

        return [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')];
    }

    /**
     * Strictly parse a Y-m-d date string.
     *
     * @param string|null $value
     * @return DateTime|null
     */
    private static function parse(?string $value): ?DateTime
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $date = DateTime::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }
}
