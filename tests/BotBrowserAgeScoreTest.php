<?php

/**
 * Browser age scores of the bot detection, measured against computed current
 * versions for a fixed day, through both the DeviceDetector and the regex path.
 *
 * Run with:  php tests/BotBrowserAgeScoreTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/bootstrap.php';

use DeviceDetector\DeviceDetector;
use giantbits\crelish\commands\BotDetectionController;

class AgeScoreController extends BotDetectionController
{
    public function ageScore(string $userAgent, bool $withDeviceDetector): int
    {
        $dd = null;
        if ($withDeviceDetector) {
            $dd = new DeviceDetector($userAgent);
            $dd->parse();
        }

        return $this->getBrowserAgeScore($userAgent, $dd);
    }

    public function dead(string $userAgent, bool $withDeviceDetector): bool
    {
        $dd = null;
        if ($withDeviceDetector) {
            $dd = new DeviceDetector($userAgent);
            $dd->parse();
        }

        return $this->hasDeadBrowser($userAgent, $dd);
    }
}

new \yii\console\Application(['id' => 'bot-age-test', 'basePath' => dirname(__DIR__)]);

$controller = new AgeScoreController('bot-detection', Yii::$app);
$controller->today = '2026-10-08';

$chrome = static fn(int $v): string => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/$v.0.0.0 Safari/537.36";
$cases = [
    'Chrome 154 (outdated 2 days) scores 0' => [0, $chrome(154)],
    'Chrome 149 (outdated 163 days) scores 0' => [0, $chrome(149)],
    'Chrome 148 (outdated 191 days) scores 20' => [20, $chrome(148)],
    'Chrome 142 (outdated 359 days) scores 20' => [20, $chrome(142)],
    'Chrome 141 (outdated 387 days) scores 30' => [30, $chrome(141)],
    'Chrome 128 (outdated 751 days) scores 40' => [40, $chrome(128)],
    'Chrome 100 (outdated 1535 days) scores 50' => [50, $chrome(100)],
    'Firefox 150 (outdated 177 days) scores 20' => [20, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:150.0) Gecko/20100101 Firefox/150.0'],
    'iOS 26 scores 0' => [0, 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1'],
    'iOS 18 (2 years) scores 30' => [30, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1'],
    'iOS 26 Safari with the frozen OS 18_6 scores 0' => [0, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1'],
    'iPad Safari 26 with the frozen OS 18_6 scores 0' => [0, 'Mozilla/5.0 (iPad; CPU OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1'],
    'Chrome on iOS with the frozen OS 18_6 scores 0' => [0, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/154.0.7339.122 Mobile/15E148 Safari/604.1'],
    'old Chrome on iOS with the frozen OS 18_6 scores its Chrome version' => [20, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/142.0.7339.122 Mobile/15E148 Safari/604.1'],
    'Safari 18 with the frozen OS 18_6 scores its Version (2 years)' => [30, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1'],
    'genuine iOS 16 (4 years) scores 40' => [40, 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1'],
    'iOS 13 (7 years) scores 50' => [50, 'Mozilla/5.0 (iPhone; CPU iPhone OS 13_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.2 Mobile/15E148 Safari/604.1'],
    'desktop Safari 17 (3 years) scores 40' => [40, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15'],
    'desktop Safari 26 scores 0' => [0, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15'],
    'frozen Android with current Chrome scores 0' => [0, 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36'],
    'Android 13 (4 years) scores 30' => [30, 'Mozilla/5.0 (Linux; Android 13; SM-A536B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36'],
    'Android 9 (8 years) scores 50' => [50, 'Mozilla/5.0 (Linux; Android 9; SM-G960F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36'],
    'Android 15 (1 year) scores 0' => [0, 'Mozilla/5.0 (Linux; Android 15; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36'],
];

foreach (['DeviceDetector' => true, 'regex fallback' => false] as $path => $withDd) {
    echo "Through the $path\n";
    foreach ($cases as $name => [$expected, $ua]) {
        check($name, $expected, $controller->ageScore($ua, $withDd));
    }
    echo "\n";
}

echo "Frozen Android OS is never scored\n";
check('frozen Android with old Chrome scores the Chrome version only', 30, $controller->ageScore(
    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', true
));

echo "\nChrome and Firefox across the switch to the 2-week cadence\n";
$controller->today = '2027-03-16';
check('2027-03-16: Chrome 166 is current, scores 0', 0, $controller->ageScore($chrome(166), true));
check('2027-03-16: Chrome 155 (outdated 147 days) scores 0', 0, $controller->ageScore($chrome(155), true));
check('2027-03-16: Chrome 153 (outdated 175 days) scores 20', 20, $controller->ageScore($chrome(153), true));
check('2027-03-16: Chrome 145 (outdated 1 year+) scores 30', 30, $controller->ageScore($chrome(145), true));
check('2027-03-16: Firefox 156 (outdated 168 days) scores 20', 20, $controller->ageScore('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:156.0) Gecko/20100101 Firefox/156.0', false));
$controller->today = '2026-10-08';

echo "\niOS in-app browsers (WKWebView) are neither dead nor outdated\n";
$inApp = [
    'LinkedIn' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [LinkedInApp]/9.31.1734',
    'Instagram' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 389.0.0.29.81 (iPhone15,3; iOS 26_0; de_DE; de-DE; scale=3.00; 1290x2796; 735151209; IABMV/1)',
    'Facebook' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/520.0.0.38.101;FBBV/750123456;FBDV/iPhone15,3;FBMD/iPhone;FBSN/iOS;FBSV/26.0;FBSS/3;FBCR/;FBID/phone;FBLC/de_DE;FBOP/5;FBRV/0]',
    'XING on iPad' => 'Mozilla/5.0 (iPad; CPU OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 XING/9.80.0',
];
foreach ($inApp as $app => $ua) {
    foreach (['DeviceDetector' => true, 'regex' => false] as $path => $withDd) {
        check("$app ($path): not a dead browser, age score 0", [false, 0], [$controller->dead($ua, $withDd), $controller->ageScore($ua, $withDd)]);
    }
}
check('KHTML without Chrome/Safari outside an iOS WebView is still dead', true, $controller->dead('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) SomeCrawler/1.0', false));

echo "\nThe day is injectable\n";
$controller->today = '2026-01-15';
check('Chrome 142 on 2026-01-15 (outdated 93 days) scores 0', 0, $controller->ageScore($chrome(142), true));
$controller->today = null;
$current = \giantbits\crelish\components\Analytics\BrowserVersions::chrome(new \DateTimeImmutable('today'));
check('without a day, today\'s Chrome scores 0', 0, $controller->ageScore($chrome($current), true));
check('without a day, Chrome 60 behind today scores 50', 50, $controller->ageScore($chrome($current - 60), true));

analyticsDone();
