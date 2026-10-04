<?php

/**
 * MenuService: tree building, skipping rules, labels and translations,
 * caching and invalidation.
 *
 * Run with:  php tests/MenuServiceTest.php
 */

declare(strict_types=1);

require __DIR__ . '/menu/bootstrap.php';

use giantbits\crelish\components\ContentUrlResolver;
use giantbits\crelish\components\menus\MenuService;
use giantbits\crelish\models\MenuItem;

const P_NEWS = 'b0000000-0000-4000-8000-000000000001';
const P_KONTAKT = 'b0000000-0000-4000-8000-000000000002';
const P_DRAFT = 'b0000000-0000-4000-8000-000000000003';

$pages = [
    'page/' . P_NEWS => (object)['uuid' => P_NEWS, 'state' => 2, 'systitle' => 'News (Liste)', 'navtitle' => 'News', 'slug' => 'news'],
    'page/' . P_KONTAKT => (object)['uuid' => P_KONTAKT, 'state' => 2, 'systitle' => 'Kontakt', 'navtitle' => '', 'slug' => 'kontakt'],
    'page/' . P_DRAFT => (object)['uuid' => P_DRAFT, 'state' => 1, 'systitle' => 'Entwurf', 'slug' => 'entwurf'],
];
$service = new MenuService(new ContentUrlResolver(fn(string $ctype, string $uuid) => $pages["$ctype/$uuid"] ?? null));

function labels(array $nodes): array
{
    return array_map(fn(array $n) => $n['label'], $nodes);
}

$app = menuApp();
$menu = makeMenu('main');
$page = fn(string $uuid, array $extra = []) => array_merge(['target_type' => MenuItem::TARGET_CONTENT, 'target_ctype' => 'page', 'target_uuid' => $uuid, 'target_url' => null], $extra);

$news = makeItem($menu, $page(P_NEWS, ['sort' => 0]));
$about = makeItem($menu, ['sort' => 2, 'label' => 'Über uns', 'target_type' => MenuItem::TARGET_NONE, 'target_url' => null]);
makeItem($menu, $page(P_KONTAKT, ['parent_uuid' => $about->uuid, 'sort' => 0]));
makeItem($menu, ['parent_uuid' => $about->uuid, 'sort' => 1, 'label' => 'LinkedIn', 'target_url' => 'https://www.linkedin.com/company/forum-holzbau/', 'new_window' => 1]);
makeItem($menu, ['sort' => 1, 'label' => 'Termine', 'target_url' => '/de/veranstaltungen']);
makeItem($menu, ['sort' => 3, 'label' => 'Versteckt', 'state' => MenuItem::STATE_OFFLINE]);
$draft = makeItem($menu, $page(P_DRAFT, ['sort' => 4, 'label' => 'Entwurf']));
makeItem($menu, ['parent_uuid' => $draft->uuid, 'label' => 'Kind vom Entwurf']);
$lonely = makeItem($menu, ['sort' => 5, 'label' => 'Leere Gruppe', 'target_type' => MenuItem::TARGET_NONE, 'target_url' => null]);
makeItem($menu, $page(P_DRAFT, ['parent_uuid' => $lonely->uuid, 'label' => 'Nur Entwurf']));

$tree = $service->build('main', 'de');

echo "Tree\n";
check('top level order and skipping', ['News', 'Termine', 'Über uns'], labels($tree));
check('children kept in order', ['Kontakt', 'LinkedIn'], labels($tree[2]['children']));
check('content url resolved', '/de/news', $tree[0]['url']);
check('target uuid exposed', P_NEWS, $tree[0]['targetUuid']);
check('heading has no url', null, $tree[2]['url']);
check('external is not part of the built tree (set per request by get())', [false, true], [$tree[2]['children'][1]['external'], $tree[2]['children'][1]['newWindow']]);
check('flags default to false', [false, false], [$tree[0]['active'], $tree[0]['activeTrail']]);

echo "\nSkipping\n";
check('offline item skipped', false, in_array('Versteckt', labels($tree), true));
check('dead target skipped with its subtree', false, in_array('Entwurf', labels($tree), true));
check('heading whose only child is dead is hidden', false, in_array('Leere Gruppe', labels($tree), true));

echo "\nLabels\n";
check('empty label falls back to navtitle', 'News', $tree[0]['label']);
check('empty navtitle falls back to systitle', 'Kontakt', $tree[2]['children'][0]['label']);
$termine = MenuItem::findOne(['label' => 'Termine']);
$termine->setTranslations(['en' => ['label' => 'Events']]);
$termine->save(false);
Yii::$app->language = 'en';
$en = $service->build('main', 'en');
check('translated label', 'Events', $en[1]['label']);
check('missing translation falls back to default label', 'Über uns', $en[2]['label']);
check('content url in the requested language', '/en/news', $en[0]['url']);
Yii::$app->language = 'de';

echo "\nDepth and unknown menus\n";
$flat = makeMenu('flat', ['max_depth' => 1]);
$top = makeItem($flat, ['label' => 'Top']);
makeItem($flat, ['parent_uuid' => $top->uuid, 'label' => 'Too deep']);
check('children beyond max_depth are dropped', [], $service->build('flat', 'de')[0]['children']);
check('unknown key gives an empty menu', [], $service->build('nope', 'de'));
makeMenu('off', ['state' => 0]);
check('offline menu gives an empty menu', [], $service->build('off', 'de'));

echo "\nCache\n";
$cached = $service->tree('main');
Yii::$app->db->createCommand()->update('menu_item', ['label' => 'Termine (neu)'], ['uuid' => $termine->uuid])->execute();
check('second call served from cache', 'Termine', $service->tree('main')[1]['label']);
MenuService::invalidate();
check('invalidate() rebuilds', 'Termine (neu)', $service->tree('main')[1]['label']);
$termine = MenuItem::findOne($termine->uuid);
$termine->label = 'Termine';
$termine->save(false);
check('item save invalidates', 'Termine', $service->tree('main')[1]['label']);
$menu->systitle = 'Hauptmenü';
$menu->save();
Yii::$app->db->createCommand()->update('menu_item', ['label' => 'Direkt'], ['uuid' => $termine->uuid])->execute();
$termine->delete();
check('item delete invalidates', ['News', 'Über uns'], labels($service->tree('main')));
Yii::$app->language = 'en';
check('cache is per language', 'News', $service->tree('main')[0]['label']);
Yii::$app->language = 'de';

echo "\nInvalidation after commit\n";
$txMenu = makeMenu('txtest');
$txItem = makeItem($txMenu, ['label' => 'Alt']);
check('tx tree warmed', 'Alt', $service->tree('txtest')[0]['label']);
$transaction = Yii::$app->db->beginTransaction();
$txItem->label = 'Neu';
$txItem->save(false);
check('item save inside a transaction leaves the cache alone until commit', 'Alt', $service->tree('txtest')[0]['label']);
$inner = Yii::$app->db->beginTransaction();
$inner->commit();
check('a nested commit does not invalidate yet', 'Alt', $service->tree('txtest')[0]['label']);
$transaction->commit();
check('commit of the outermost transaction invalidates', 'Neu', $service->tree('txtest')[0]['label']);
$transaction = Yii::$app->db->beginTransaction();
$txItem->label = 'Verworfen';
$txItem->save(false);
$transaction->rollBack();
check('rolled back save keeps the (still correct) cache', 'Neu', $service->tree('txtest')[0]['label']);
$transaction = Yii::$app->db->beginTransaction();
$transaction->commit();
check('a later unrelated commit does not fire a stale handler', true, Yii::$app->cache->exists('crelish.menu.txtest.de'));
$txItem->label = 'Direkt';
$txItem->save(false);
check('save without a transaction still invalidates immediately', 'Direkt', $service->tree('txtest')[0]['label']);
check('menu tree warmed before delete', 1, count($service->tree('txtest')));
$transaction = Yii::$app->db->beginTransaction();
$txMenu->delete();
check('menu delete inside an outer transaction waits for commit', 1, count($service->tree('txtest')));
$transaction->commit();
check('menu is gone from the cache after commit', [], $service->tree('txtest'));
$txMenu = makeMenu('txtest2');
makeItem($txMenu, ['label' => 'Eins']);
check('second menu warmed', 1, count($service->tree('txtest2')));
$txMenu->delete();
check('menu delete (own transaction) leaves a fresh cache', [], $service->tree('txtest2'));

// The translation behavior loads by the full locale, so the cache must be keyed by it too
$locales = makeMenu('locales');
$colour = makeItem($locales, ['label' => 'Farbe', 'target_url' => '/de/farbe']);
foreach (['en-US' => 'Color', 'en-GB' => 'Colour'] as $locale => $text) {
    Yii::$app->db->createCommand()->insert('translation', ['uuid' => \giantbits\crelish\components\CrelishBaseHelper::GUIDv4(), 'language' => $locale,
        'source_model' => MenuItem::tableName(), 'source_model_uuid' => $colour->uuid, 'source_model_attribute' => 'label', 'translation' => $text])->execute();
}
Yii::$app->language = 'en-US';
$us = $service->tree('locales')[0];
Yii::$app->language = 'en-GB';
$gb = $service->tree('locales')[0];
check('full locales get their own cache entries', [true, true], [Yii::$app->cache->exists('crelish.menu.locales.en-US'), Yii::$app->cache->exists('crelish.menu.locales.en-GB')]);
check('labels follow the full locale through the cache', ['Color', 'Colour'], [$us['label'], $gb['label']]);
check('content urls keep the two-letter code', ['/en/news', '/en/news'], (function () use ($service) {
    $urls = [];
    foreach (['en-US', 'en-GB'] as $locale) {
        Yii::$app->language = $locale;
        $urls[] = $service->tree('main')[0]['url'];
    }
    return $urls;
})());
Yii::$app->language = 'de';

Yii::$app->set('cache', new class extends \yii\caching\ArrayCache {
    protected function setValue($key, $value, $duration)
    {
        throw new \RuntimeException('cache backend down');
    }

    protected function setValues($data, $duration)
    {
        throw new \RuntimeException('cache backend down');
    }
});
$threw = false;
try {
    MenuService::invalidate();
} catch (\Throwable $e) {
    $threw = true;
}
check('invalidate() survives a failing cache', false, $threw);

shortLinkDone();
