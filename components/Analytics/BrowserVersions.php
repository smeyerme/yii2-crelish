<?php

namespace giantbits\crelish\components\Analytics;

/**
 * Current browser and OS versions, computed from the date so they never go stale.
 *
 * Chrome and Firefox switched to a 2-week release cadence: from the switch on,
 * major N ships 14 days after N - 1. Releases before the switch are counted
 * backwards from it in 4-week steps (counting forward from an older release
 * drifted weeks ahead of the real dates, because of holiday and summer gaps).
 * iOS/Safari and Android are compared by release year, because their version
 * numbers do not map linearly to age (Apple jumped from 18 to 26 in 2025).
 *
 * Pure: every method is static and takes the day it is asked about.
 */
final class BrowserVersions
{
    /** Per browser: [switch major, switch date, step days before, step days from the switch] */
    private const SCHEDULES = [
        // Chrome 153 (2026-09-08) opened the 2-week cadence
        'chrome' => [153, '2026-09-08', 28, 14],
        // Firefox 155 (2026-09-01) opened the 2-week cadence
        'firefox' => [155, '2026-09-01', 28, 14],
    ];

    /** Majors above this are not versions but garbage (crafted user agents) */
    public const MAX_MAJOR = 10000;

    /**
     * Release day of a major version ('chrome' or 'firefox'): the switch
     * anchor plus 14-day steps from the switch on, minus 28-day steps before it.
     *
     * @throws \InvalidArgumentException for an unknown browser or a major outside 1..MAX_MAJOR
     */
    public static function releaseDate(string $browser, int $major): \DateTimeImmutable
    {
        [$switchMajor, $switchDate, $stepBefore, $stepFrom] = self::schedule($browser);
        if ($major < 1 || $major > self::MAX_MAJOR) {
            throw new \InvalidArgumentException("No release date for {$browser} {$major}");
        }

        $days = ($major - $switchMajor) * ($major >= $switchMajor ? $stepFrom : $stepBefore);

        return (new \DateTimeImmutable($switchDate, new \DateTimeZone('UTC')))
            ->modify(sprintf('%+d days', $days));
    }

    /**
     * Newest major version released on or before $today ('chrome' or 'firefox').
     */
    public static function current(string $browser, \DateTimeImmutable $today): int
    {
        [$switchMajor, $switchDate, $stepBefore, $stepFrom] = self::schedule($browser);

        $sinceSwitch = self::daysSince($switchDate, $today);
        if ($sinceSwitch >= 0) {
            return $switchMajor + intdiv($sinceSwitch, $stepFrom);
        }

        // Before the switch: the newest major whose backwards-counted date has passed
        return $switchMajor - intdiv(-$sinceSwitch + $stepBefore - 1, $stepBefore);
    }

    /**
     * How many days a major version has been outdated on $today: days since its
     * successor (major + 1) was released. Negative when there is no successor yet.
     *
     * @throws \InvalidArgumentException for a major outside 1..MAX_MAJOR
     */
    public static function outdatedDays(string $browser, int $major, \DateTimeImmutable $today): int
    {
        if ($major < 1 || $major >= self::MAX_MAJOR) {
            throw new \InvalidArgumentException("No release date for {$browser} {$major}");
        }

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
     * Whether the user agent carries a frozen iOS version, which Safari 26+,
     * Chrome on iOS and in-app browsers send regardless of the real iOS
     * version, so its OS version says nothing; the browser version has to be
     * scored instead. The frozen value is "OS 18_6" or, from later iOS 26
     * releases, "OS 18_7" (seen with Version/26.6.1); a Safari version above
     * the OS version shows the same for any value still to come.
     */
    public static function isFrozenIos(string $userAgent): bool
    {
        if (!preg_match('/(?:iPhone OS|CPU OS) (\d+)_(\d+)/', $userAgent, $os)) {
            return false;
        }
        if ((int)$os[1] === 18 && in_array((int)$os[2], [6, 7], true)) {
            return true;
        }

        return preg_match('/Version\/(\d+)\.\d+.*Safari/', $userAgent, $safari)
            && (int)$safari[1] > (int)$os[1];
    }

    /** @return array{int, string, int, int} */
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
