<?php

/**
 * Generic content URL resolution: strategies, publication, language,
 * labels, detailPages config and resolvable types.
 *
 * Run with:  php tests/ContentUrlResolverTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\ContentUrlResolver;
use giantbits\crelish\components\UrlTargetInterface;
use giantbits\crelish\components\shortlinks\ShortLinkTargetInterface;

class NewStyleTarget implements UrlTargetInterface
{
    public string $uuid = 'f0000000-0000-4000-8000-000000000001';
    public int $state = 2;
    public string $systitle = 'Seminar';

    public function getTargetUrl(?string $language): ?string
    {
        return '/' . $language . '/seminare/holzbau';
    }
}

class OldStyleTarget implements ShortLinkTargetInterface
{
    public string $uuid = 'f0000000-0000-4000-8000-000000000002';
    public int $state = 2;

    public function getShortLinkUrl(?string $language): ?string
    {
        return 'https://events.example.com/ihf?lang=' . $language;
    }
}

const NEWS = 'a0000000-0000-4000-8000-000000000001';
const PAGE = 'b0000000-0000-4000-8000-000000000001';

function contentRecords(array $overrides = []): array
{
    return array_merge([
        'news/' . NEWS => (object)['uuid' => NEWS, 'state' => 2, 'systitle' => 'Holzbau Forum 2026: Programm', 'from' => null, 'to' => null],
        'page/' . PAGE => (object)['uuid' => PAGE, 'state' => 2, 'systitle' => 'Programm (intern)', 'navtitle' => 'Programm', 'slug' => 'programm'],
        'seminar/' . (new NewStyleTarget())->uuid => new NewStyleTarget(),
        'event/' . (new OldStyleTarget())->uuid => new OldStyleTarget(),
        'sponsor/s1' => (object)['uuid' => 's1', 'state' => 2, 'systitle' => 'Sponsor'],
    ], $overrides);
}

function contentResolver(array $records): ContentUrlResolver
{
    return new ContentUrlResolver(fn(string $ctype, string $uuid) => $records["$ctype/$uuid"] ?? null);
}

$now = strtotime('2026-09-29 12:00');

shortLinkApp(['languages' => ['de', 'en'], 'detailPages' => ['news' => 'news', 'event' => 'events']]);
$r = contentResolver(contentRecords());

echo "Target types\n";
check('url passes through', 'https://www.example.com/x', $r->resolve('url', null, null, 'https://www.example.com/x', null, $now));
check('empty url is unavailable', null, $r->resolve('url', null, null, '', null, $now));
check('none has no url', null, $r->resolve('none', null, null, null, null, $now));
check('unknown type has no url', null, $r->resolve('bogus', 'page', PAGE, null, null, $now));

echo "\nContent strategies\n";
check('page resolves by slug (site-relative)', '/de/programm', $r->resolve('content', 'page', PAGE, null, null, $now));
check('news resolves through detailPages', '/de/news/' . NEWS . '/holzbau-forum-2026-programm', $r->resolveContent('news', NEWS, null, $now));
check('UrlTargetInterface wins', '/de/seminare/holzbau', $r->resolveContent('seminar', (new NewStyleTarget())->uuid, null, $now));
check('deprecated ShortLinkTargetInterface still works', 'https://events.example.com/ihf?lang=de', $r->resolveContent('event', (new OldStyleTarget())->uuid, null, $now));
check('type without mapping or slug is unavailable', null, $r->resolveContent('sponsor', 's1', null, $now));
check('missing record is unavailable', null, $r->resolveContent('news', 'missing', null, $now));

echo "\nPublication\n";
$draft = contentResolver(contentRecords(['news/' . NEWS => (object)['uuid' => NEWS, 'state' => 1, 'systitle' => 'X']]));
check('unpublished record is unavailable', null, $draft->resolveContent('news', NEWS, null, $now));
$ended = contentResolver(contentRecords(['news/' . NEWS => (object)['uuid' => NEWS, 'state' => 2, 'systitle' => 'X', 'to' => '2026-09-28']]));
check('ended window is unavailable', null, $ended->resolveContent('news', NEWS, null, $now));

echo "\nLanguage\n";
check('explicit language', '/en/programm', $r->resolveContent('page', PAGE, 'en', $now));
Yii::$app->language = 'en-GB';
check('defaults to the app language', '/en/programm', $r->resolveContent('page', PAGE, null, $now));
check('currentLanguage is two letters', 'en', ContentUrlResolver::currentLanguage());
Yii::$app->language = 'de';

echo "\nLabels and titles\n";
check('label prefers navtitle', 'Programm', $r->resolveLabel('page', PAGE));
check('label falls back to systitle', 'Sponsor', $r->resolveLabel('sponsor', 's1'));
check('label of missing record', null, $r->resolveLabel('page', 'missing'));
check('title is systitle', 'Programm (intern)', $r->resolveTitle('page', PAGE));

echo "\ndetailPages config\n";
check('top-level detailPages', ['news' => 'news', 'event' => 'events'], ContentUrlResolver::detailPages());
shortLinkApp(['shortLinks' => ['detailPages' => ['news' => 'aktuell']]]);
check('falls back to shortLinks.detailPages', ['news' => 'aktuell'], ContentUrlResolver::detailPages());
shortLinkApp(['shortLinks' => ['enabled' => false, 'detailPages' => ['news' => 'aktuell']]]);
check('works with shortlinks disabled', ['news' => 'aktuell'], ContentUrlResolver::detailPages());

echo "\nResolvable types\n";
check('page is resolvable', true, ContentUrlResolver::canResolveType('page'));
check('mapped type is resolvable', true, ContentUrlResolver::canResolveType('news'));
check('unmapped type without model is not', false, ContentUrlResolver::canResolveType('sponsor'));
check('resolvable types are sorted', ['news', 'page'], ContentUrlResolver::resolvableTypes());

shortLinkDone();
