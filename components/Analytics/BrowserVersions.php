<?php

namespace giantbits\crelish\components\Analytics;

/**
 * Current browser and OS versions, computed from the date so they never go stale.
 *
 * Chrome and Firefox ship a new major every 4 weeks; their current version is
 * counted from a known stable release. iOS/Safari and Android are compared by
 * release year, because their version numbers do not map linearly to age
 * (Apple jumped from 18 to 26 in 2025).
 *
 * Pure: every method is static and takes the day it is asked about.
 */
final class BrowserVersions
{
    /** Chrome 131 reached stable on this day */
    private const CHROME_BASE_VERSION = 131;
    private const CHROME_BASE_DATE = '2024-11-12';

    /** Firefox 133 was released on this day */
    private const FIREFOX_BASE_VERSION = 133;
    private const FIREFOX_BASE_DATE = '2024-11-26';

    /** Days between two major releases */
    private const RELEASE_CADENCE_DAYS = 28;

    public static function chrome(\DateTimeImmutable $today): int
    {
        return self::CHROME_BASE_VERSION
            + intdiv(self::daysSince(self::CHROME_BASE_DATE, $today), self::RELEASE_CADENCE_DAYS);
    }

    public static function firefox(\DateTimeImmutable $today): int
    {
        return self::FIREFOX_BASE_VERSION
            + intdiv(self::daysSince(self::FIREFOX_BASE_DATE, $today), self::RELEASE_CADENCE_DAYS);
    }

    /**
     * Release year of an iOS / Safari major version: 26 = 2025 (year-based
     * numbering), up to 25 the old scheme where 18 = 2024 and 13 = 2019.
     */
    public static function appleYear(int $major): int
    {
        return $major >= 26 ? 1999 + $major : 2006 + $major;
    }

    /**
     * Year of the newest iOS / Safari release (Apple ships each September).
     */
    public static function currentAppleYear(\DateTimeImmutable $today): int
    {
        $year = (int)$today->format('Y');

        return (int)$today->format('n') >= 9 ? $year : $year - 1;
    }

    /**
     * Release year of an Android major version: 16 = 2025.
     */
    public static function androidYear(int $major): int
    {
        return 2009 + $major;
    }

    /**
     * Year of the newest Android release (Google ships around June).
     */
    public static function currentAndroidYear(\DateTimeImmutable $today): int
    {
        $year = (int)$today->format('Y');

        return (int)$today->format('n') >= 6 ? $year : $year - 1;
    }

    /**
     * Whether the user agent is the reduced Android UA ("Android 10; K"), which
     * Chrome and other Chromium browsers send regardless of the real Android
     * version, so its OS version says nothing about the device's age.
     */
    public static function isFrozenAndroid(string $userAgent): bool
    {
        return (bool)preg_match('/Android 10; K[;)]/', $userAgent);
    }

    private static function daysSince(string $base, \DateTimeImmutable $today): int
    {
        $utc = new \DateTimeZone('UTC');
        $from = new \DateTimeImmutable($base, $utc);
        $to = new \DateTimeImmutable($today->format('Y-m-d'), $utc);

        $days = (int)$from->diff($to)->days;

        return $to < $from ? -$days : $days;
    }
}
