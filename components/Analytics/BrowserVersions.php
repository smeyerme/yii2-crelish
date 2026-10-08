<?php

namespace giantbits\crelish\components\Analytics;

/**
 * Current browser and OS versions, computed from the date so they never go stale.
 *
 * Chrome and Firefox follow a piecewise release schedule: 4-week steps from an
 * old anchor until they switched to a 2-week cadence, 2-week steps from the
 * switch on. iOS/Safari and Android are compared by release year, because
 * their version numbers do not map linearly to age (Apple jumped from 18 to
 * 26 in 2025).
 *
 * Pure: every method is static and takes the day it is asked about.
 */
final class BrowserVersions
{
    /**
     * Per browser: [old anchor major, old anchor date, old step days,
     * switch major, switch date, new step days]
     */
    private const SCHEDULES = [
        // Chrome 131 stable 2024-11-12; Chrome 153 (2026-09-08) opened the 2-week cadence
        'chrome' => [131, '2024-11-12', 28, 153, '2026-09-08', 14],
        // Firefox 133 2024-11-26; Firefox 155 (2026-09-01) opened the 2-week cadence
        'firefox' => [133, '2024-11-26', 28, 155, '2026-09-01', 14],
    ];

    /**
     * Release day of a major version ('chrome' or 'firefox'). Before the switch
     * this is the old anchor plus 28-day steps, from the switch on the switch
     * anchor plus 14-day steps.
     */
    public static function releaseDate(string $browser, int $major): \DateTimeImmutable
    {
        [$oldMajor, $oldDate, $oldStep, $switchMajor, $switchDate, $newStep] = self::schedule($browser);

        [$anchorMajor, $anchorDate, $step] = $major >= $switchMajor
            ? [$switchMajor, $switchDate, $newStep]
            : [$oldMajor, $oldDate, $oldStep];

        $days = ($major - $anchorMajor) * $step;

        return (new \DateTimeImmutable($anchorDate, new \DateTimeZone('UTC')))
            ->modify(sprintf('%+d days', $days));
    }

    /**
     * Newest major version released on or before $today ('chrome' or 'firefox').
     */
    public static function current(string $browser, \DateTimeImmutable $today): int
    {
        [$oldMajor, $oldDate, $oldStep, $switchMajor, $switchDate, $newStep] = self::schedule($browser);

        $sinceSwitch = self::daysSince($switchDate, $today);
        if ($sinceSwitch >= 0) {
            return $switchMajor + intdiv($sinceSwitch, $newStep);
        }

        // Before the switch: 4-week steps, never past the last version before it
        return min($switchMajor - 1, $oldMajor + intdiv(self::daysSince($oldDate, $today), $oldStep));
    }

    /**
     * How many days a major version has been outdated on $today: days since its
     * successor (major + 1) was released. Negative when there is no successor yet.
     */
    public static function outdatedDays(string $browser, int $major, \DateTimeImmutable $today): int
    {
        return self::daysSince(self::releaseDate($browser, $major + 1)->format('Y-m-d'), $today);
    }

    public static function chrome(\DateTimeImmutable $today): int
    {
        return self::current('chrome', $today);
    }

    public static function firefox(\DateTimeImmutable $today): int
    {
        return self::current('firefox', $today);
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
     * Year of the newest iOS / Safari release. Apple ships in September; the
     * year switches on October 1 so the first weeks after a release (while
     * most devices still run last year's version) are not counted as behind.
     */
    public static function currentAppleYear(\DateTimeImmutable $today): int
    {
        $year = (int)$today->format('Y');

        return (int)$today->format('n') >= 10 ? $year : $year - 1;
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

    /**
     * Whether the user agent carries the frozen iOS version "OS 18_6", which
     * Safari 26+ and Chrome on iOS send regardless of the real iOS version, so
     * its OS version says nothing; the browser version has to be scored instead.
     */
    public static function isFrozenIos(string $userAgent): bool
    {
        return (bool)preg_match('/(?:iPhone OS|CPU OS) 18_6(?!\d)/', $userAgent);
    }

    /** @return array{int, string, int, int, string, int} */
    private static function schedule(string $browser): array
    {
        $browser = strtolower($browser);
        if (!isset(self::SCHEDULES[$browser])) {
            throw new \InvalidArgumentException("No release schedule for '{$browser}'");
        }

        return self::SCHEDULES[$browser];
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
