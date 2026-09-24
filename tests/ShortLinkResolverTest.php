<?php

/**
 * Short link resolution: state and validity, external and content targets,
 * resolution order, record publication windows, language, fallback chain.
 *
 * Run with:  php tests/ShortLinkResolverTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\shortlinks\ResolveResult;
use giantbits\crelish\components\shortlinks\ShortLinkResolver;
use giantbits\crelish\components\shortlinks\ShortLinkTargetInterface;
use giantbits\crelish\models\ShortLink;

class EventTarget implements ShortLinkTargetInterface
{
    public string $uuid = 'e0000000-0000-4000-8000-000000000001';
    public int $state = 2;

    public function getShortLinkUrl(?string $language): ?string
    {
        return 'https://events.example.com/ihf?lang=' . $language;
    }
}

const NEWS_UUID = 'a0000000-0000-4000-8000-000000000001';
const PAGE_UUID = 'b0000000-0000-4000-8000-000000000001';

function records(array $overrides = []): array
{
    return array_merge([
        'news/' . NEWS_UUID => (object)['uuid' => NEWS_UUID, 'state' => 2, 'systitle' => 'Holzbau Forum 2026: Programm', 'from' => null, 'to' => null],
        'page/' . PAGE_UUID => (object)['uuid' => PAGE_UUID, 'state' => 2, 'systitle' => 'Programm', 'slug' => 'programm'],
        'event/' . (new EventTarget())->uuid => new EventTarget(),
        'sponsor/s1' => (object)['uuid' => 's1', 'state' => 2, 'systitle' => 'Sponsor'],
    ], $overrides);
}

function resolver(array $records): ShortLinkResolver
{
    return new ShortLinkResolver(fn(string $ctype, string $uuid) => $records["$ctype/$uuid"] ?? null);
}

function urlLink(array $attributes = []): ShortLink
{
    $link = new ShortLink();
    $link->setAttributes(array_merge([
        'systitle' => 'Flyer', 'code' => 'ihf26', 'state' => 2,
        'target_type' => ShortLink::TARGET_URL, 'target_url' => 'https://www.example.com/ihf',
    ], $attributes), false);

    return $link;
}

function contentLink(string $ctype, string $uuid, array $attributes = []): ShortLink
{
    return urlLink(array_merge(['target_type' => ShortLink::TARGET_CONTENT, 'target_url' => null, 'target_ctype' => $ctype, 'target_uuid' => $uuid], $attributes));
}

$now = strtotime('2026-09-24 12:00');
$site = 'https://forum-holzbau.test';

shortLinkApp(['languages' => ['de', 'en'], 'shortLinks' => ['detailPages' => ['news' => 'news', 'event' => 'events']]]);
$resolve = resolver(records());

echo "External targets and link state\n";
$result = $resolve->resolve(urlLink(), null, $now);
check('online url target', 'https://www.example.com/ihf', $result->url);
check('online url target is not a fallback', false, $result->isFallback());

$result = $resolve->resolve(urlLink(['state' => 0, 'fallback_url' => 'https://www.example.com/archiv']), null, $now);
check('offline link uses its fallback', 'https://www.example.com/archiv', $result->url);
check('offline link reason', ResolveResult::REASON_INACTIVE, $result->reason);
check('offline link without fallback goes home', "$site/de", $resolve->resolve(urlLink(['state' => 1]), null, $now)->url);
check('scheduled link falls back', ResolveResult::REASON_INACTIVE, $resolve->resolve(urlLink(['valid_from' => $now + 60]), null, $now)->reason);
check('expired link falls back', ResolveResult::REASON_INACTIVE, $resolve->resolve(urlLink(['valid_until' => $now - 60]), null, $now)->reason);

echo "\nContent targets\n";
check('page resolves by slug', "$site/de/programm", $resolve->resolve(contentLink('page', PAGE_UUID), null, $now)->url);
check('news resolves through detailPages', "$site/de/news/" . NEWS_UUID . '/holzbau-forum-2026-programm', $resolve->resolve(contentLink('news', NEWS_UUID), null, $now)->url);
check('interface wins over detailPages', 'https://events.example.com/ihf?lang=de', $resolve->resolve(contentLink('event', (new EventTarget())->uuid), null, $now)->url);

$result = $resolve->resolve(contentLink('news', 'missing'), null, $now);
check('missing record is broken', ResolveResult::REASON_BROKEN, $result->reason);
check('missing record goes to the site fallback', "$site/de", $result->url);
check('type without mapping or slug is broken', ResolveResult::REASON_BROKEN, $resolve->resolve(contentLink('sponsor', 's1'), null, $now)->reason);

$draft = resolver(records(['news/' . NEWS_UUID => (object)['uuid' => NEWS_UUID, 'state' => 1, 'systitle' => 'X']]));
check('unpublished record is broken', ResolveResult::REASON_BROKEN, $draft->resolve(contentLink('news', NEWS_UUID), null, $now)->reason);

echo "\nRecord publication window\n";
$ending = resolver(records(['news/' . NEWS_UUID => (object)['uuid' => NEWS_UUID, 'state' => 2, 'systitle' => 'X', 'from' => null, 'to' => '2026-09-24']]));
check('date-only "to" is valid through the end of that day', ResolveResult::REASON_OK, $ending->resolve(contentLink('news', NEWS_UUID), null, strtotime('2026-09-24 18:00'))->reason);
check('date-only "to" has ended the next day', ResolveResult::REASON_BROKEN, $ending->resolve(contentLink('news', NEWS_UUID), null, strtotime('2026-09-25 00:01'))->reason);
$starting = resolver(records(['news/' . NEWS_UUID => (object)['uuid' => NEWS_UUID, 'state' => 2, 'systitle' => 'X', 'from' => '2026-10-01', 'to' => null]]));
check('"from" in the future is broken', ResolveResult::REASON_BROKEN, $starting->resolve(contentLink('news', NEWS_UUID), null, $now)->reason);
$stamps = resolver(records(['news/' . NEWS_UUID => (object)['uuid' => NEWS_UUID, 'state' => 2, 'systitle' => 'X', 'from' => (string)($now - 10), 'to' => (string)($now + 10)]]));
check('unix timestamps are understood', ResolveResult::REASON_OK, $stamps->resolve(contentLink('news', NEWS_UUID), null, $now)->reason);
$zero = resolver(records(['news/' . NEWS_UUID => (object)['uuid' => NEWS_UUID, 'state' => 2, 'systitle' => 'X', 'from' => '0000-00-00', 'to' => '0000-00-00 00:00:00']]));
check('zero dates mean no window', ResolveResult::REASON_OK, $zero->resolve(contentLink('news', NEWS_UUID), null, $now)->reason);

echo "\nLanguage\n";
check('target_language wins', "$site/en/programm", $resolve->resolve(contentLink('page', PAGE_UUID, ['target_language' => 'en']), 'de-DE', $now)->url);
check('Accept-Language picks a supported language', "$site/en/programm", $resolve->resolve(contentLink('page', PAGE_UUID), 'en-GB,en;q=0.9,de;q=0.8', $now)->url);
check('Accept-Language respects q values', "$site/de/programm", $resolve->resolve(contentLink('page', PAGE_UUID), 'en;q=0.4,de;q=0.9', $now)->url);
check('unsupported Accept-Language uses the default', "$site/de/programm", $resolve->resolve(contentLink('page', PAGE_UUID), 'fr-FR,fr;q=0.9', $now)->url);
check('malformed Accept-Language uses the default', "$site/de/programm", $resolve->resolve(contentLink('page', PAGE_UUID), ';;;q=,', $now)->url);

echo "\nFallback chain\n";
shortLinkApp(['shortLinks' => ['fallbackUrl' => '/de/archiv']]);
check('configured site fallback is made absolute', "$site/de/archiv", resolver(records())->resolve(urlLink(['state' => 0]), null, $now)->url);

echo "\nResolvable types\n";
shortLinkApp(['shortLinks' => ['detailPages' => ['news' => 'news']]]);
check('page is resolvable', true, ShortLinkResolver::canResolveType('page'));
check('mapped type is resolvable', true, ShortLinkResolver::canResolveType('news'));
check('unmapped type without model is not', false, ShortLinkResolver::canResolveType('sponsor'));

shortLinkDone();
