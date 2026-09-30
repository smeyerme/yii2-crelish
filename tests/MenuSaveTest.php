<?php

/**
 * Whole-tree save: diffing, new parents by clientId, validation (422),
 * optimistic concurrency (409), rollback (500), translations, payload.
 *
 * Run with:  php tests/MenuSaveTest.php
 */

declare(strict_types=1);

require __DIR__ . '/menu/bootstrap.php';

use giantbits\crelish\components\ContentUrlResolver;
use giantbits\crelish\components\menus\MenuAdminTree;
use giantbits\crelish\components\menus\MenuTreeSaver;
use giantbits\crelish\models\CrelishTranslation;
use giantbits\crelish\models\Menu;
use giantbits\crelish\models\MenuItem;

const PAGE_NEWS = 'b0000000-0000-4000-8000-000000000001';
$pages = ['page/' . PAGE_NEWS => (object)['uuid' => PAGE_NEWS, 'state' => 2, 'systitle' => 'News (Liste)', 'navtitle' => 'News', 'slug' => 'news']];
$resolver = new ContentUrlResolver(fn(string $ctype, string $uuid) => $pages["$ctype/$uuid"] ?? null);

function item(array $attributes): array
{
    return array_merge(['uuid' => null, 'clientId' => null, 'parentRef' => null, 'sort' => 0, 'label' => '', 'i18n' => [],
        'target_type' => 'url', 'target_ctype' => null, 'target_uuid' => null, 'target_url' => '/de/x', 'new_window' => false, 'state' => 2], $attributes);
}

function saveTree(Menu $menu, array $items, ?int $updated = null): array
{
    global $resolver;
    $menu->refresh();

    return (new MenuTreeSaver($menu, $resolver))->save(['updated' => $updated ?? (int)$menu->updated, 'items' => $items]);
}

$app = menuApp(['languages' => ['de', 'en']]);
$menu = makeMenu('main');

echo "Insert\n";
$result = saveTree($menu, [
    item(['clientId' => 'n1', 'target_type' => 'content', 'target_ctype' => 'page', 'target_uuid' => PAGE_NEWS]),
    item(['clientId' => 'n2', 'sort' => 1, 'label' => 'Über uns', 'target_type' => 'none', 'target_url' => null, 'i18n' => ['en' => 'About']]),
    item(['clientId' => 'n3', 'parentRef' => 'n2', 'label' => 'Kontakt', 'target_url' => '/de/kontakt']),
]);
check('insert succeeds', 200, $result['status']);
check('three rows', 3, (int)MenuItem::find()->count());
$about = MenuItem::findOne(['label' => 'Über uns']);
check('child parented by clientId', $about->uuid, MenuItem::findOne(['label' => 'Kontakt'])->parent_uuid);
check('translation stored', 'About', CrelishTranslation::findOne(['source_model_uuid' => $about->uuid, 'language' => 'en'])->translation);

echo "\nPayload\n";
$body = $result['body'];
check('payload menu meta', ['main', 2], [$body['menu']['key'], $body['menu']['max_depth']]);
check('payload languages', [['de', 'en'], 'de'], [$body['languages'], $body['defaultLanguage']]);
$byLabel = [];
foreach ($body['items'] as $row) {
    $byLabel[$row['label'] !== '' ? $row['label'] : $row['fallbackLabel']] = $row;
}
check('fallback label for empty label', ['', 'News'], [$byLabel['News']['label'], $byLabel['News']['fallbackLabel']]);
check('target title and availability', ['News (Liste)', true], [$byLabel['News']['targetTitle'], $byLabel['News']['targetAvailable']]);
check('i18n exposed without default language', ['en' => 'About'], $byLabel['Über uns']['i18n']);
check('raw default label even in English admin', 'Über uns', (function () use ($menu, $resolver) {
    Yii::$app->language = 'en';
    $rows = MenuAdminTree::build($menu, $resolver)['items'];
    Yii::$app->language = 'de';
    foreach ($rows as $row) {
        if ($row['target_type'] === 'none') {
            return $row['label'];
        }
    }
    return null;
})());

echo "\nUpdate, move, delete\n";
$rows = MenuAdminTree::build($menu, $resolver)['items'];
$uuidOf = [];
foreach ($rows as $row) {
    $uuidOf[$row['label'] ?: $row['fallbackLabel']] = $row['uuid'];
}
$result = saveTree($menu, [
    item(['uuid' => $uuidOf['Über uns'], 'label' => 'Über uns', 'target_type' => 'none', 'target_url' => null, 'i18n' => ['en' => '']]),
    item(['clientId' => 'g1', 'sort' => 1, 'label' => 'Service', 'target_type' => 'none', 'target_url' => null]),
    item(['uuid' => $uuidOf['Kontakt'], 'parentRef' => 'g1', 'label' => 'Kontakt', 'target_url' => '/de/kontakt']),
]);
check('update succeeds', 200, $result['status']);
check('existing item moved under a new parent', MenuItem::findOne(['label' => 'Service'])->uuid, MenuItem::findOne($uuidOf['Kontakt'])->parent_uuid);
check('omitted item deleted', null, MenuItem::findOne($uuidOf['News']));
check('cleared translation deleted', 0, (int)CrelishTranslation::find()->where(['source_model_uuid' => $uuidOf['Über uns']])->count());

echo "\nConcurrency\n";
$menu->refresh();
$stale = (int)$menu->updated;
check('first save ok', 200, saveTree($menu, [item(['clientId' => 'a', 'label' => 'A'])], $stale)['status']);
check('second save in the same second is detected as stale', 409, saveTree($menu, [item(['clientId' => 'b', 'label' => 'B'])], $stale)['status']);
check('stale save wrote nothing', 0, (int)MenuItem::find()->where(['label' => 'B'])->count());
$menu->refresh();
check('409 body is exactly the error message', ['error' => Yii::t('crelish', 'Menu was changed by someone else. Please reload.')], saveTree($menu, [item(['clientId' => 'c', 'label' => 'C'])], (int)$menu->updated - 5)['body']);

// Another save commits between the fast-path check and this save's write
$racing = new class($menu, $resolver) extends MenuTreeSaver {
    protected function beforeVersionBump(): void
    {
        Yii::$app->db->createCommand('UPDATE menu SET updated = updated + 1 WHERE uuid = :uuid', [':uuid' => $this->menuUuid()])->execute();
    }
};
$menu->refresh();
$before = MenuItem::find()->select('label')->where(['menu_uuid' => $menu->uuid])->orderBy('label')->column();
$r = $racing->save(['updated' => (int)$menu->updated, 'items' => [item(['clientId' => 'r', 'label' => 'Race'])]]);
check('concurrent bump after the fast path gives 409', [409, ['error' => Yii::t('crelish', 'Menu was changed by someone else. Please reload.')]], [$r['status'], $r['body']]);
check('racing save wrote nothing', $before, MenuItem::find()->select('label')->where(['menu_uuid' => $menu->uuid])->orderBy('label')->column());

echo "\nValidation\n";
$errors = fn(array $items) => saveTree($menu, $items);
$r = $errors([item(['clientId' => 'x', 'parentRef' => 'ghost', 'label' => 'X'])]);
check('unknown parent', [422, true], [$r['status'], isset($r['body']['errors']['x'])]);
$r = $errors([item(['clientId' => 'a', 'parentRef' => 'b', 'label' => 'A']), item(['clientId' => 'b', 'parentRef' => 'a', 'label' => 'B'])]);
check('cycle rejected', 422, $r['status']);
$r = $errors([item(['clientId' => 'a', 'label' => 'A']), item(['clientId' => 'b', 'parentRef' => 'a', 'label' => 'B']), item(['clientId' => 'c', 'parentRef' => 'b', 'label' => 'C'])]);
check('depth above max_depth rejected on the deep item', [422, ['c']], [$r['status'], array_keys($r['body']['errors'])]);
$r = $errors([item(['clientId' => 'a', 'label' => 'A', 'target_url' => 'javascript:alert(1)'])]);
check('javascript: url rejected', 422, $r['status']);
$r = $errors([item(['clientId' => 'a', 'label' => 'A', 'target_url' => '//evil.example.com'])]);
check('protocol-relative url rejected', 422, $r['status']);
foreach (['https://example.com', 'mailto:info@example.com', 'tel:+41441234567', '/de/x', '#kontakt'] as $ok) {
    check("allowed url $ok", 200, $errors([item(['clientId' => 'a', 'label' => 'A', 'target_url' => $ok])])['status']);
}
$r = $errors([item(['clientId' => 'a', 'label' => 'A', 'target_url' => '  '])]);
check('empty url rejected', 422, $r['status']);
$r = $errors([item(['clientId' => 'a', 'target_type' => 'none', 'target_url' => null, 'label' => ''])]);
check('heading needs a label', 422, $r['status']);
$r = $errors([item(['clientId' => 'a', 'target_type' => 'content', 'target_ctype' => 'sponsor', 'target_uuid' => 'x', 'label' => 'A'])]);
check('unresolvable content type rejected', 422, $r['status']);
$r = $errors([item(['clientId' => 'a', 'target_type' => 'content', 'target_ctype' => 'page', 'target_uuid' => '', 'label' => 'A'])]);
check('content without uuid rejected', 422, $r['status']);
$r = $errors([item(['clientId' => 'a', 'target_type' => 'bogus', 'label' => 'A'])]);
check('unknown target type rejected', 422, $r['status']);
$other = makeMenu('footer');
$foreign = makeItem($other, ['label' => 'Fremd']);
$r = $errors([item(['uuid' => $foreign->uuid, 'label' => 'Fremd'])]);
check('item of another menu rejected', 422, $r['status']);
$r = $errors([item(['label' => 'No id'])]);
check('new item without clientId rejected', 422, $r['status']);
$r = $errors([item(['clientId' => 'dup', 'label' => 'A']), item(['clientId' => 'dup', 'label' => 'B'])]);
check('duplicate item id rejected under that id', [422, [Yii::t('crelish', 'Duplicate item.')]], [$r['status'], $r['body']['errors']['dup'] ?? null]);
$r = $errors([item(['clientId' => 'a', 'label' => str_repeat('x', 256)])]);
check('label longer than 255 rejected', 422, $r['status']);
$i18nError = 'Die Übersetzung (en) muss ein Text mit höchstens 255 Zeichen sein.';
$r = $errors([item(['clientId' => 'a', 'label' => 'A', 'i18n' => ['en' => ['x']]])]);
check('array translation rejected with a message', [422, [$i18nError]], [$r['status'], $r['body']['errors']['a'] ?? null]);
$r = $errors([item(['clientId' => 'a', 'label' => 'A', 'i18n' => ['en' => 12]])]);
check('number translation rejected', 422, $r['status']);
$r = $errors([item(['clientId' => 'a', 'label' => 'A', 'i18n' => ['en' => str_repeat('ü', 256)]])]);
check('translation longer than 255 rejected', [422, [$i18nError]], [$r['status'], $r['body']['errors']['a'] ?? null]);
check('translation of exactly 255 accepted', 200, $errors([item(['clientId' => 'a', 'label' => 'A', 'i18n' => ['en' => str_repeat('ü', 255)]])])['status']);
check('null translation accepted as empty', 200, $errors([item(['clientId' => 'a', 'label' => 'A', 'i18n' => ['en' => null]])])['status']);
check('failed validation wrote nothing', ['A'], MenuItem::find()->select('label')->where(['menu_uuid' => $menu->uuid])->column());
$r = $errors([item(['clientId' => 'a', 'label' => 'A', 'i18n' => ['en' => 'A (en)', 'fr' => ['ignored'], 'de' => 'ignored too']])]);
check('unknown or default language keys are ignored', 200, $r['status']);
$savedA = MenuItem::findOne(['menu_uuid' => $menu->uuid, 'label' => 'A']);
check('only configured non-default translations stored', ['en'], CrelishTranslation::find()->select('language')->where(['source_model_uuid' => $savedA->uuid])->column());

echo "\nRollback\n";
// Raw PDO: Yii's SQLite command splits on ';' and would cut the trigger body
Yii::$app->db->pdo->exec('CREATE TRIGGER fail_insert BEFORE INSERT ON menu_item WHEN NEW.label = \'Boom\' BEGIN SELECT RAISE(ABORT, \'boom\'); END');
$before = MenuItem::find()->select('label')->where(['menu_uuid' => $menu->uuid])->column();
$r = $errors([item(['clientId' => 'a', 'label' => 'Neu']), item(['clientId' => 'b', 'sort' => 1, 'label' => 'Boom'])]);
check('database error gives 500', 500, $r['status']);
check('500 body is exactly the error message', ['error' => Yii::t('crelish', 'The menu could not be saved.')], $r['body']);
check('rolled back', $before, MenuItem::find()->select('label')->where(['menu_uuid' => $menu->uuid])->column());

echo "\nCache\n";
Yii::$app->db->pdo->exec('DROP TRIGGER fail_insert');
$cacheMenu = makeMenu('cachetest');
$cacheItem = makeItem($cacheMenu, ['label' => 'Alt']);
$service = new \giantbits\crelish\components\menus\MenuService($resolver);
check('tree warmed', 'Alt', $service->tree('cachetest')[0]['label']);
$r = saveTree($cacheMenu, [item(['uuid' => $cacheItem->uuid, 'label' => 'Neu'])]);
check('label change saved', 200, $r['status']);
check('successful save invalidates the cached tree', 'Neu', $service->tree('cachetest')[0]['label']);

shortLinkDone();
