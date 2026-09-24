<?php

/**
 * Short link codes and the ShortLink model: code rules, validation,
 * status, short URLs and QR payload.
 *
 * Run with:  php tests/ShortLinkCodeTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\shortlinks\ShortLinkCode;
use giantbits\crelish\models\ShortLink;

function makeLink(array $attributes = []): ShortLink
{
    $link = new ShortLink();
    $link->setAttributes(array_merge([
        'systitle' => 'IHF 2026 Flyer',
        'code' => 'ihf26',
        'target_type' => ShortLink::TARGET_URL,
        'target_url' => 'https://www.example.com/ihf',
        'state' => ShortLink::STATE_ONLINE,
    ], $attributes), false);

    return $link;
}

echo "Short link codes\n";
shortLinkApp();

$bad = [];
for ($i = 0; $i < 200; $i++) {
    $code = ShortLinkCode::random();
    if (strlen($code) !== ShortLinkCode::LENGTH || strspn($code, ShortLinkCode::ALPHABET) !== ShortLinkCode::LENGTH) {
        $bad[] = $code;
    }
}
check('generated codes are 6 characters from the unambiguous alphabet', [], $bad);

$calls = 0;
ShortLinkCode::generate(function () use (&$calls): bool {
    return ++$calls <= 3;
});
check('generator retries until a free code is found', 4, $calls);

try {
    ShortLinkCode::generate(fn(): bool => true, 5);
    $threw = false;
} catch (RuntimeException) {
    $threw = true;
}
check('generator gives up after max attempts', true, $threw);

check('normalize lowercases and trims', 'ihf26', ShortLinkCode::normalize('  IHF26 '));
check('two characters are allowed', null, ShortLinkCode::formatError('ab'));
check('one character is rejected', true, ShortLinkCode::formatError('a') !== null);
check('leading hyphen is rejected', true, ShortLinkCode::formatError('-ab') !== null);
check('trailing hyphen is rejected', true, ShortLinkCode::formatError('ab-') !== null);
check('underscore is rejected', true, ShortLinkCode::formatError('ab_c') !== null);
check('64 characters are allowed', null, ShortLinkCode::formatError(str_repeat('a', 64)));
check('65 characters are rejected', true, ShortLinkCode::formatError(str_repeat('a', 65)) !== null);
check('built-in reserved word is rejected', true, ShortLinkCode::formatError('crelish') !== null);
check('the prefix itself is reserved', true, ShortLinkCode::formatError('go') !== null);

shortLinkApp(['shortLinks' => ['reservedCodes' => ['Messe']]]);
check('configured reserved codes are compared lowercase', true, ShortLinkCode::formatError('messe') !== null);

echo "\nShortLink validation\n";
shortLinkApp();

$link = makeLink();
check('valid external link saves', true, $link->save());
check('uuid is generated', 36, strlen((string) $link->uuid));
check('created timestamp is set', true, (int) $link->created > 0);

foreach ([
    'javascript scheme' => 'javascript:alert(1)',
    'data scheme' => 'data:text/html,<script>alert(1)</script>',
    'relative url' => '/de/programm',
    'ftp scheme' => 'ftp://example.com/file',
] as $label => $url) {
    $candidate = makeLink(['code' => 'x' . substr(md5($label), 0, 5), 'target_url' => $url]);
    check("target_url rejects $label", true, !$candidate->validate() && $candidate->hasErrors('target_url'));
    $candidate = makeLink(['code' => 'y' . substr(md5($label), 0, 5), 'fallback_url' => $url]);
    check("fallback_url rejects $label", true, !$candidate->validate() && $candidate->hasErrors('fallback_url'));
}

$candidate = makeLink(['code' => 'IHF-27']);
$candidate->validate();
check('code is normalised to lowercase on validation', 'ihf-27', $candidate->code);

$duplicate = makeLink(['code' => 'IHF26']);
check('code differing only by case is a duplicate', true, !$duplicate->validate() && $duplicate->hasErrors('code'));

$reserved = makeLink(['code' => 'sitemap']);
check('reserved code fails validation', true, !$reserved->validate() && $reserved->hasErrors('code'));

$content = makeLink(['code' => 'cnt01', 'target_type' => ShortLink::TARGET_CONTENT, 'target_url' => null]);
$content->validate();
check('content target requires ctype', true, $content->hasErrors('target_ctype'));
check('content target requires uuid', true, $content->hasErrors('target_uuid'));

$window = makeLink(['code' => 'win01', 'valid_from' => 2000, 'valid_until' => 1000]);
check('valid_until before valid_from is rejected', true, !$window->validate() && $window->hasErrors('valid_until'));

$qr = makeLink(['code' => 'qr001', 'qr_quiet_zone' => 3, 'qr_color' => 'red', 'qr_size_mm' => 5]);
$qr->validate();
check('quiet zone below 4 modules is rejected', true, $qr->hasErrors('qr_quiet_zone'));
check('colour must be #rrggbb', true, $qr->hasErrors('qr_color'));
check('size below 10 mm is rejected', true, $qr->hasErrors('qr_size_mm'));

$dates = makeLink(['code' => 'dat01']);
$dates->validFromInput = '2026-10-01T09:00';
check('validFromInput sets a timestamp', strtotime('2026-10-01 09:00'), $dates->valid_from);
check('validFromInput formats back', '2026-10-01T09:00', $dates->validFromInput);
$dates->validFromInput = '';
check('empty validFromInput clears the timestamp', null, $dates->valid_from);

$switch = makeLink(['code' => 'sw001', 'target_type' => ShortLink::TARGET_CONTENT, 'target_ctype' => 'news', 'target_uuid' => str_repeat('a', 36)]);
check('content link saves', true, $switch->save());
check('saving a content link clears target_url', null, $switch->target_url);

$form = makeLink(['code' => 'frm01']);
$loaded = $form->loadForm([
    'ShortLink' => ['systitle' => 'Changed', 'validUntilInput' => '2026-12-31T23:59'],
    'CrelishDynamicModel' => ['logo_asset_uuid' => 'b1946ac9-2d6e-4c5f-8a3c-2f1c5f3e9a10'],
]);
check('loadForm reports loaded', true, $loaded);
check('loadForm assigns fields', 'Changed', $form->systitle);
check('loadForm converts the date input', strtotime('2026-12-31 23:59'), $form->valid_until);
check('loadForm takes the logo from the asset connector field', 'b1946ac9-2d6e-4c5f-8a3c-2f1c5f3e9a10', $form->logo_asset_uuid);
$form->loadForm(['CrelishDynamicModel' => ['logo_asset_uuid' => '']]);
check('an emptied asset connector clears the logo', null, $form->logo_asset_uuid);

echo "\nShortLink status\n";
$now = strtotime('2026-09-24 12:00');
check('online without window', ShortLink::STATUS_ONLINE, makeLink()->getStatus($now));
check('scheduled before valid_from', ShortLink::STATUS_SCHEDULED, makeLink(['valid_from' => $now + 60])->getStatus($now));
check('expired after valid_until', ShortLink::STATUS_EXPIRED, makeLink(['valid_until' => $now - 60])->getStatus($now));
check('offline state', ShortLink::STATUS_OFFLINE, makeLink(['state' => 0])->getStatus($now));
check('draft state', ShortLink::STATUS_DRAFT, makeLink(['state' => 1])->getStatus($now));
check('archived state', ShortLink::STATUS_ARCHIVED, makeLink(['state' => 3])->getStatus($now));
check('only online is live', false, makeLink(['state' => 1])->isLive($now));
check('online is live', true, makeLink()->isLive($now));

echo "\nShort URLs\n";
$saved = ShortLink::findByCode('IHF26');
check('findByCode is case-insensitive', 'ihf26', $saved?->code);
check('short URL on the site host', 'https://forum-holzbau.test/go/ihf26', $saved->getShortUrl());
check('QR short URL carries /q', 'https://forum-holzbau.test/go/ihf26/q', $saved->getShortUrl(true));
check('QR payload is uppercase', 'HTTPS://FORUM-HOLZBAU.TEST/GO/IHF26/Q', $saved->getQrPayload());

shortLinkApp(['shortLinks' => ['siteUrl' => 'https://forum-holzbau.com/']]);
check('configured siteUrl wins over the request host', 'https://forum-holzbau.com/go/ihf26', makeLink()->getShortUrl());

shortLinkApp(['shortLinks' => ['shortHost' => 'FHB.link']]);
check('short host drops the prefix', 'https://fhb.link/ihf26', makeLink()->getShortUrl());
check('short host QR payload', 'HTTPS://FHB.LINK/IHF26/Q', makeLink()->getQrPayload());

shortLinkDone();
