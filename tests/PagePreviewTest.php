<?php

/**
 * Signed preview links for unpublished pages: tokens, the serve decision,
 * the preview URL and the preview-mode response.
 *
 * Run with:  php tests/PagePreviewTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\PagePreview;

const PREVIEW_PAGE = 'b0000000-0000-4000-8000-0000000000aa';
const OTHER_PAGE = 'b0000000-0000-4000-8000-0000000000bb';

$now = strtotime('2026-10-05 12:00:00');

/**
 * Flip one character in the middle of the token to another base64url character.
 */
function flipChar(string $token, int $position): string
{
    $token[$position] = $token[$position] === 'A' ? 'B' : 'A';

    return $token;
}

shortLinkApp();

echo "Tokens\n";
$token = PagePreview::createToken(PREVIEW_PAGE, $now);
check('token is url-safe', 1, preg_match('/^[A-Za-z0-9_-]+$/', $token));
check('roundtrip is valid', true, PagePreview::validateToken($token, PREVIEW_PAGE, $now));
check('valid just before expiry', true, PagePreview::validateToken($token, PREVIEW_PAGE, $now + 86400));
check('expired after the default TTL', false, PagePreview::validateToken($token, PREVIEW_PAGE, $now + 86401));
check('token for another page', false, PagePreview::validateToken($token, OTHER_PAGE, $now));

$tampered = true;
for ($i = 0; $i < strlen($token); $i++) {
    if (PagePreview::validateToken(flipChar($token, $i), PREVIEW_PAGE, $now)) {
        $tampered = false;
        echo "         flipping position $i still validates\n";
    }
}
check('every flipped character invalidates', true, $tampered);
check('trailing garbage invalidates', false, PagePreview::validateToken($token . 'A', PREVIEW_PAGE, $now));
check('truncated token invalidates', false, PagePreview::validateToken(substr($token, 0, -1), PREVIEW_PAGE, $now));
check('padding is not accepted', false, PagePreview::validateToken($token . '=', PREVIEW_PAGE, $now));

foreach (['', 'x', 'not a token', '!!!!', str_repeat('A', 5000), base64_encode('garbage'), "\0\xff"] as $i => $garbage) {
    check("garbage #$i is invalid", false, PagePreview::validateToken($garbage, PREVIEW_PAGE, $now));
}

$forged = rtrim(strtr(base64_encode(str_repeat('0', 64) . 'p1|' . PREVIEW_PAGE . '|' . ($now + 3600)), '+/', '-_'), '=');
check('forged hash is invalid', false, PagePreview::validateToken($forged, PREVIEW_PAGE, $now));

echo "\nTTL\n";
shortLinkApp(['previewTtl' => 600]);
$short = PagePreview::createToken(PREVIEW_PAGE, $now);
check('configured TTL: valid inside', true, PagePreview::validateToken($short, PREVIEW_PAGE, $now + 600));
check('configured TTL: expired after', false, PagePreview::validateToken($short, PREVIEW_PAGE, $now + 601));

echo "\nKeys\n";
shortLinkApp();
$byCookieKey = PagePreview::createToken(PREVIEW_PAGE, $now);
shortLinkApp(['previewSecret' => 'a-dedicated-preview-secret']);
$bySecret = PagePreview::createToken(PREVIEW_PAGE, $now);
check('previewSecret signs differently than the cookie key', false, $bySecret === $byCookieKey);
check('previewSecret token is valid with the secret', true, PagePreview::validateToken($bySecret, PREVIEW_PAGE, $now));
check('cookie-key token is invalid once a secret is set', false, PagePreview::validateToken($byCookieKey, PREVIEW_PAGE, $now));
shortLinkApp();
check('secret token is invalid with the cookie key', false, PagePreview::validateToken($bySecret, PREVIEW_PAGE, $now));
check('cookie-key token is valid with the cookie key', true, PagePreview::validateToken($byCookieKey, PREVIEW_PAGE, $now));
shortLinkApp([], [], ['components' => ['request' => ['cookieValidationKey' => 'another-key']]]);
check('another cookie key invalidates', false, PagePreview::validateToken($byCookieKey, PREVIEW_PAGE, $now));

shortLinkApp(['previewSecret' => ''], [], ['components' => ['request' => ['cookieValidationKey' => '', 'enableCookieValidation' => false]]]);
check('without any key previews are disabled', false, PagePreview::isEnabled());
check('without any key nothing validates', false, PagePreview::validateToken($byCookieKey, PREVIEW_PAGE, $now));
$threw = false;
try {
    PagePreview::createToken(PREVIEW_PAGE, $now);
} catch (\yii\base\InvalidConfigException) {
    $threw = true;
}
check('without any key createToken throws InvalidConfigException', true, $threw);
shortLinkApp();
check('with a key previews are enabled', true, PagePreview::isEnabled());

echo "\nshouldServe\n";
$token = PagePreview::createToken(PREVIEW_PAGE, $now);
$draft = (object)['uuid' => PREVIEW_PAGE, 'state' => 1, 'slug' => 'programm'];
$scheduled = (object)['uuid' => PREVIEW_PAGE, 'state' => 2, 'from' => '2026-12-01', 'slug' => 'programm'];
$published = (object)['uuid' => PREVIEW_PAGE, 'state' => 2, 'slug' => 'programm'];
check('unpublished + valid token', true, PagePreview::shouldServe($draft, $token, $now));
check('outside window + valid token', true, PagePreview::shouldServe($scheduled, $token, $now));
check('unpublished + no token', false, PagePreview::shouldServe($draft, null, $now));
check('unpublished + empty token', false, PagePreview::shouldServe($draft, '', $now));
check('unpublished + invalid token', false, PagePreview::shouldServe($draft, 'garbage', $now));
check('unpublished + array token', false, PagePreview::shouldServe($draft, [$token], $now));
check('unpublished + expired token', false, PagePreview::shouldServe($draft, $token, $now + 86401));
check('unpublished + token of another page', false, PagePreview::shouldServe((object)['uuid' => OTHER_PAGE, 'state' => 1], $token, $now));
check('unpublished without uuid', false, PagePreview::shouldServe((object)['state' => 1], $token, $now));
check('published page is never a preview', false, PagePreview::shouldServe($published, $token, $now));

echo "\nURL\n";
shortLinkApp(['languages' => ['de', 'en']]);
Yii::$app->language = 'en';
$url = PagePreview::url($draft, $now);
$token = PagePreview::createToken(PREVIEW_PAGE, $now);
check('url is the absolute page url in the default content language + token', 'https://forum-holzbau.test/de/programm?preview=' . $token, $url);
check('home page url', 'https://forum-holzbau.test/de?preview=' . $token, PagePreview::url((object)['uuid' => PREVIEW_PAGE, 'slug' => 'home'], $now));
shortLinkApp(['langprefix' => false]);
check('url without language prefix', 'https://forum-holzbau.test/programm?preview=' . $token, PagePreview::url($draft, $now));
check('url of a page without slug is null', null, PagePreview::url((object)['uuid' => PREVIEW_PAGE], $now));
check('url of a page without uuid is null', null, PagePreview::url((object)['slug' => 'programm'], $now));

echo "\nPreview mode\n";
shortLinkApp();
$view = Yii::$app->view;
PagePreview::registerPreviewMode($view, Yii::$app->response);
check('X-Robots-Tag header', 'noindex, nofollow', Yii::$app->response->headers->get('X-Robots-Tag'));
check('Cache-Control header', 'no-store, private', Yii::$app->response->headers->get('Cache-Control'));
check('robots meta tag', true, str_contains(implode('', $view->metaTags), '<meta name="robots" content="noindex, nofollow">'));
ob_start();
$view->beginBody();
$body = ob_get_clean();
check('banner rendered at the start of the body', true, str_contains($body, 'Vorschau – diese Seite ist nicht veröffentlicht'));
check('banner is marked as preview banner', true, str_contains($body, 'crelish-preview-banner'));

shortLinkDone();
