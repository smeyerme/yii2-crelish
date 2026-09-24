<?php

/**
 * Short link redirect: destination, headers, tracking, no open redirect,
 * and graceful degradation when tracking or the table fails.
 *
 * Run with:  php tests/ShortLinkRedirectTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\controllers\ShortLinkRedirectController;
use giantbits\crelish\models\ShortLink;
use yii\db\Query;
use yii\log\Logger;
use yii\web\NotFoundHttpException;

function seed(array $attributes = []): ShortLink
{
    $link = new ShortLink();
    $link->setAttributes(array_merge([
        'systitle' => 'Flyer', 'code' => 'ihf26', 'state' => 2,
        'target_type' => ShortLink::TARGET_URL, 'target_url' => 'https://www.example.com/ihf',
    ], $attributes), false);
    $link->save(false);

    return $link;
}

function hit(string $code, int $scan = 0, string $action = 'index'): \yii\web\Response
{
    Yii::$app->response->clear();
    $controller = new ShortLinkRedirectController('short-link-redirect', Yii::$app);

    return $controller->runAction($action, $action === 'index' ? ['code' => $code, 'scan' => $scan] : []);
}

function eventTypes(): array
{
    return (new Query())->select('type')->from('analytics_element_views')->orderBy('id')->column();
}

function logCount(string $category, int $level): int
{
    return count(array_filter(Yii::getLogger()->messages, fn($m) => $m[2] === $category && $m[1] === $level));
}

echo "Redirects\n";
shortLinkApp();
seed();
$response = hit('ihf26');
check('status is 302', 302, $response->statusCode);
check('goes to the target', 'https://www.example.com/ihf', $response->headers->get('location'));
check('not cacheable', 'no-store', $response->headers->get('cache-control'));
check('not indexable', 'noindex', $response->headers->get('x-robots-tag'));
check('click is tracked', ['click'], eventTypes());

hit('IHF26', 1);
check('uppercase code works and scan is tracked', ['click', 'scan'], eventTypes());

echo "\nNo open redirect\n";
$_GET = ['redirect' => 'https://evil.example/', 'url' => 'https://evil.example/', 'target' => 'https://evil.example/'];
check('query parameters cannot change the destination', 'https://www.example.com/ihf', hit('ihf26')->headers->get('location'));
$_GET = [];

echo "\nUnknown and inactive links\n";
$before = count(eventTypes());
check('unknown code goes home', 'https://forum-holzbau.test/de', hit('nope99')->headers->get('location'));
check('unknown code is not tracked', $before, count(eventTypes()));

seed(['code' => 'off001', 'state' => 0, 'fallback_url' => 'https://www.example.com/archiv']);
check('offline link goes to its fallback', 'https://www.example.com/archiv', hit('off001', 1)->headers->get('location'));
check('fallback hit is tracked as fallback', 'fallback', array_slice(eventTypes(), -1)[0]);

echo "\nBroken content target\n";
seed(['code' => 'brk001', 'target_type' => ShortLink::TARGET_CONTENT, 'target_url' => null, 'target_ctype' => 'news', 'target_uuid' => 'a0000000-0000-4000-8000-000000000009']);
$warnings = logCount('shortlink', Logger::LEVEL_WARNING);
check('broken target goes home', 'https://forum-holzbau.test/de', hit('brk001')->headers->get('location'));
hit('brk001');
check('broken target warns once per day', $warnings + 1, logCount('shortlink', Logger::LEVEL_WARNING));

echo "\nFailures degrade gracefully\n";
shortLinkApp();
seed();
Yii::$app->db->createCommand()->dropTable('analytics_element_views')->execute();
$errors = logCount('analytics', Logger::LEVEL_ERROR);
check('tracking failure still redirects', 'https://www.example.com/ihf', hit('ihf26')->headers->get('location'));
check('tracking failure is logged', $errors + 1, logCount('analytics', Logger::LEVEL_ERROR));

shortLinkApp();
Yii::$app->db->createCommand()->dropTable('shortlink')->execute();
check('missing table goes home', 'https://forum-holzbau.test/de', hit('ihf26')->headers->get('location'));

echo "\nShort host home and disabled feature\n";
shortLinkApp();
check('home action goes to the main site', 'https://forum-holzbau.test/de', hit('', 0, 'home')->headers->get('location'));

shortLinkApp(['shortLinks' => ['enabled' => false]]);
try {
    hit('ihf26');
    $notFound = false;
} catch (NotFoundHttpException) {
    $notFound = true;
}
check('disabled feature is a 404', true, $notFound);

shortLinkDone();
