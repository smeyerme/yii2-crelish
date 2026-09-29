<?php

/**
 * Active state: uuid match, longest prefix, site roots, external URLs,
 * active trail; MenuService::get() end to end.
 *
 * Run with:  php tests/MenuActiveStateTest.php
 */

declare(strict_types=1);

require __DIR__ . '/menu/bootstrap.php';

use giantbits\crelish\components\ContentUrlResolver;
use giantbits\crelish\components\menus\MenuActiveState;
use giantbits\crelish\components\menus\MenuService;
use giantbits\crelish\models\MenuItem;

function node(string $label, ?string $url, array $children = [], ?string $targetUuid = null): array
{
    return ['uuid' => $label, 'label' => $label, 'url' => $url, 'type' => $url === null ? 'none' : 'url', 'targetUuid' => $targetUuid,
        'external' => false, 'newWindow' => false, 'active' => false, 'activeTrail' => false, 'children' => $children];
}

function flags(array $nodes, string $prefix = ''): array
{
    $out = [];
    foreach ($nodes as $n) {
        if ($n['active']) {
            $out[] = $prefix . $n['label'] . ':active';
        }
        if ($n['activeTrail']) {
            $out[] = $prefix . $n['label'] . ':trail';
        }
        $out = array_merge($out, flags($n['children'], $prefix . $n['label'] . '/'));
    }
    return $out;
}

$roots = ['/', '/de', '/en'];
$tree = [
    node('Home', '/de'),
    node('News', '/de/news', [], 'uuid-news'),
    node('Newsletter', '/de/newsletter'),
    node('Über uns', null, [node('Kontakt', '/de/kontakt', [], 'uuid-kontakt'), node('Presse', 'https://forum-holzbau.test/de/presse')]),
    node('Extern', 'https://example.com/de/news'),
    node('Mail', 'mailto:info@example.com'),
];
$apply = fn(?string $uuid, string $path) => flags(MenuActiveState::apply($tree, $uuid, $path, 'forum-holzbau.test', $roots));

echo "Matching\n";
check('exact path', ['News:active'], $apply(null, '/de/news'));
check('detail page matches listing prefix', ['News:active'], $apply(null, '/de/news/abc/holzbau-forum'));
check('prefix needs a segment boundary', ['Newsletter:active'], $apply(null, '/de/newsletter'));
check('uuid match without path match', ['News:active'], $apply('uuid-news', '/de/aktuelles'));
check('home item only matches exactly', [], $apply(null, '/de/impressum'));
check('home item on the home page', ['Home:active'], $apply(null, '/de'));
check('trailing slash ignored', ['News:active'], $apply(null, '/de/news/'));
check('external url never matches', ['News:active'], $apply(null, '/de/news'));

echo "\nTrail\n";
check('child active puts the parent on the trail', ['Über uns:trail', 'Über uns/Kontakt:active'], $apply(null, '/de/kontakt'));
check('same-host absolute url matches', ['Über uns:trail', 'Über uns/Presse:active'], $apply(null, '/de/presse/2026'));
check('nothing matches', [], $apply(null, '/de/unbekannt'));

echo "\nLongest prefix\n";
$nested = [node('Bauten', '/de/bauten'), node('Bauten 2026', '/de/bauten/2026')];
check('only the longest prefix counts', ['Bauten 2026:active'], flags(MenuActiveState::apply($nested, null, '/de/bauten/2026/haus-x', 'forum-holzbau.test', $roots)));

echo "\nMenuService::get()\n";
$app = menuApp([], ['REQUEST_URI' => '/de/news/abc/slug']);
$menu = makeMenu('main');
makeItem($menu, ['label' => 'News', 'target_url' => '/de/news']);
makeItem($menu, ['label' => 'Termine', 'target_url' => '/de/termine', 'sort' => 1]);
$service = new MenuService(new ContentUrlResolver(fn() => null));
check('request path drives active state', ['News:active'], flags($service->get('main')));
check('cached tree stays flag-free', false, $service->tree('main')[0]['active']);
check('unknown key', [], $service->get('missing'));
Yii::$app->set('cache', new class extends \yii\caching\ArrayCache {
    public function getOrSet($key, $callable, $duration = null, $dependency = null)
    {
        throw new RuntimeException('cache down');
    }
});
check('errors never break the page', [], $service->get('main'));

shortLinkDone();
