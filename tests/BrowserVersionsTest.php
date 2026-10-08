<?php

/**
 * Current browser and OS versions are computed from the date, never hardcoded.
 *
 * Run with:  php tests/BrowserVersionsTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/bootstrap.php';

use giantbits\crelish\components\Analytics\BrowserVersions;

$day = static fn(string $ymd): \DateTimeImmutable => new \DateTimeImmutable($ymd);

echo "Chrome and Firefox: 2-week cadence from the switch, 4-week steps counted back from it\n";
$release = static fn(string $browser, int $major): string => BrowserVersions::releaseDate($browser, $major)->format('Y-m-d');
check('Chrome 153 opens the 2-week cadence', '2026-09-08', $release('chrome', 153));
check('Chrome 154', '2026-09-22', $release('chrome', 154));
check('Chrome 155', '2026-10-06', $release('chrome', 155));
check('Chrome 156', '2026-10-20', $release('chrome', 156));
check('Chrome 152: 28 days before the switch', '2026-08-11', $release('chrome', 152));
check('Chrome 143: 10 x 28 days before the switch', '2025-12-02', $release('chrome', 143));
check('Chrome 131', '2024-12-31', $release('chrome', 131));
check('Firefox 155 opens the 2-week cadence', '2026-09-01', $release('firefox', 155));
check('Firefox 158', '2026-10-13', $release('firefox', 158));
check('Firefox 154: 28 days before the switch', '2026-08-04', $release('firefox', 154));
check('Firefox 133', '2024-12-24', $release('firefox', 133));
checkThrows('an absurd major is refused', fn() => BrowserVersions::releaseDate('chrome', 99999999999), \InvalidArgumentException::class);
checkThrows('a major below 1 is refused', fn() => BrowserVersions::releaseDate('chrome', 0), \InvalidArgumentException::class);
checkThrows('outdatedDays of PHP_INT_MAX is refused', fn() => BrowserVersions::outdatedDays('firefox', PHP_INT_MAX, $day('2026-10-08')), \InvalidArgumentException::class);

check('Chrome on 2026-01-15 is 144', 144, BrowserVersions::current('chrome', $day('2026-01-15')));
check('Chrome on 2026-07-01 is 150', 150, BrowserVersions::current('chrome', $day('2026-07-01')));
check('Chrome on 152\'s release day', 152, BrowserVersions::current('chrome', $day('2026-08-11')));
check('Chrome the day before 152 is 151', 151, BrowserVersions::current('chrome', $day('2026-08-10')));
check('Chrome the day before the switch is 152', 152, BrowserVersions::current('chrome', $day('2026-09-07')));
check('Chrome on 2026-09-08 is 153', 153, BrowserVersions::current('chrome', $day('2026-09-08')));
check('Chrome on 2026-09-21 is still 153', 153, BrowserVersions::current('chrome', $day('2026-09-21')));
check('Chrome on 2026-09-22 is 154', 154, BrowserVersions::current('chrome', $day('2026-09-22')));
check('Chrome on 2026-10-08 is 155', 155, BrowserVersions::current('chrome', $day('2026-10-08')));
check('Chrome on 2026-10-20 is 156', 156, BrowserVersions::current('chrome', $day('2026-10-20')));
check('Chrome on 2027-03-16 is 166 (14-day steps from 153)', 166, BrowserVersions::current('chrome', $day('2027-03-16')));
check('chrome() is current(chrome)', 155, BrowserVersions::chrome($day('2026-10-08')));
check('Firefox on 2026-01-15 is 146', 146, BrowserVersions::current('firefox', $day('2026-01-15')));
check('Firefox the day before the switch is 154', 154, BrowserVersions::current('firefox', $day('2026-08-31')));
check('Firefox on 2026-09-01 is 155', 155, BrowserVersions::current('firefox', $day('2026-09-01')));
check('Firefox on 2026-10-08 is 157', 157, BrowserVersions::current('firefox', $day('2026-10-08')));
check('Firefox on 2026-10-13 is 158', 158, BrowserVersions::current('firefox', $day('2026-10-13')));
check('firefox() is current(firefox)', 157, BrowserVersions::firefox($day('2026-10-08')));

echo "\nDays a version has been outdated (since its successor shipped)\n";
check('Chrome 142 on 2026-10-08: 143 shipped 310 days ago', 310, BrowserVersions::outdatedDays('chrome', 142, $day('2026-10-08')));
check('Chrome 154 on 2026-10-08: 155 shipped 2 days ago', 2, BrowserVersions::outdatedDays('chrome', 154, $day('2026-10-08')));
check('the current Chrome is not outdated', true, BrowserVersions::outdatedDays('chrome', 155, $day('2026-10-08')) < 0);
checkThrows('an unknown browser is refused', fn() => BrowserVersions::current('opera', $day('2026-10-08')), \InvalidArgumentException::class);

echo "\nApple (iOS / Safari) release years\n";
check('13 is 2019', 2019, BrowserVersions::appleYear(13));
check('18 is 2024', 2024, BrowserVersions::appleYear(18));
check('26 is 2025 (after the 18 -> 26 jump)', 2025, BrowserVersions::appleYear(26));
check('27 is 2026', 2026, BrowserVersions::appleYear(27));
check('newest Apple release on 2026-09-30 is from 2025', 2025, BrowserVersions::currentAppleYear($day('2026-09-30')));
check('newest Apple release on 2026-10-01 is from 2026', 2026, BrowserVersions::currentAppleYear($day('2026-10-01')));

echo "\nAndroid\n";
check('Android 16 is 2025', 2025, BrowserVersions::androidYear(16));
check('Android 10 is 2019', 2019, BrowserVersions::androidYear(10));
check('newest Android on 2026-05-31 is from 2025', 2025, BrowserVersions::currentAndroidYear($day('2026-05-31')));
check('newest Android on 2026-06-01 is from 2026', 2026, BrowserVersions::currentAndroidYear($day('2026-06-01')));

echo "\nFrozen Android user agent\n";
check('reduced Chrome Android UA is frozen', true, BrowserVersions::isFrozenAndroid(
    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36'
));
check('reduced Samsung Internet UA is frozen', true, BrowserVersions::isFrozenAndroid(
    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/28.0 Chrome/130.0.0.0 Mobile Safari/537.36'
));
check('Samsung Internet UA with a real model is not frozen', false, BrowserVersions::isFrozenAndroid(
    'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36'
));
check('a real Android 10 device is not frozen', false, BrowserVersions::isFrozenAndroid(
    'Mozilla/5.0 (Linux; Android 10; SM-G973F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/83.0.4103.106 Mobile Safari/537.36'
));
check('a desktop UA is not frozen Android', false, BrowserVersions::isFrozenAndroid(
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'
));

echo "\nFrozen iOS user agent\n";
check('iOS 26 Safari (OS 18_6) is frozen', true, BrowserVersions::isFrozenIos(
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1'
));
check('iPad (CPU OS 18_6) is frozen', true, BrowserVersions::isFrozenIos(
    'Mozilla/5.0 (iPad; CPU OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1'
));
check('iOS 18.5 is not frozen', false, BrowserVersions::isFrozenIos(
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1'
));
check('iOS 16.7 is not frozen', false, BrowserVersions::isFrozenIos(
    'Mozilla/5.0 (iPhone; CPU iPhone OS 16_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1'
));

analyticsDone();
