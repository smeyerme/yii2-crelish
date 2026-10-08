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
}

new \yii\console\Application(['id' => 'bot-age-test', 'basePath' => dirname(__DIR__)]);

$controller = new AgeScoreController('bot-detection', Yii::$app);
$controller->today = '2026-10-08';

$chrome = static fn(int $v): string => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/$v.0.0.0 Safari/537.36";
$cases = [
    'Chrome 142 (13 behind) scores 30' => [30, $chrome(142)],
    'Chrome 149 (6 behind) scores 20' => [20, $chrome(149)],
    'Chrome 154 (1 behind) scores 0' => [0, $chrome(154)],
    'Chrome 103 (52 behind) scores 50' => [50, $chrome(103)],
    'Firefox 150 (7 behind) scores 20' => [20, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:150.0) Gecko/20100101 Firefox/150.0'],
    'iOS 26 scores 0' => [0, 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1'],
    'iOS 18 (2 years) scores 30' => [30, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1'],
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
    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Mobile Safari/537.36', true
));

echo "\nThe day is injectable\n";
$controller->today = '2026-01-15';
check('Chrome 142 on 2026-01-15 (4 behind) scores 0', 0, $controller->ageScore($chrome(142), true));
$controller->today = null;
$current = \giantbits\crelish\components\Analytics\BrowserVersions::chrome(new \DateTimeImmutable('today'));
check('without a day, today\'s Chrome scores 0', 0, $controller->ageScore($chrome($current), true));
check('without a day, Chrome 52 behind today scores 50', 50, $controller->ageScore($chrome($current - 52), true));

analyticsDone();
