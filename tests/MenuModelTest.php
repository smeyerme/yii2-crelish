<?php

/**
 * Menu tables and models: schema, translation table, key rules, depth
 * bounds, key immutability, cascading item deletion.
 *
 * Run with:  php tests/MenuModelTest.php
 */

declare(strict_types=1);

require __DIR__ . '/menu/bootstrap.php';

use giantbits\crelish\migrations\m260929_120000_create_menu_tables;
use giantbits\crelish\models\CrelishTranslation;
use giantbits\crelish\models\Menu;
use giantbits\crelish\models\MenuItem;

$app = menuApp();

echo "Schema\n";
check('menu table exists', true, $app->db->getTableSchema('menu', true) !== null);
check('menu_item table exists', true, $app->db->getTableSchema('menu_item', true) !== null);
check('translation table created when missing', true, $app->db->getTableSchema('translation', true) !== null);

$app = shortLinkApp();
$app->db->createCommand()->createTable('translation', ['uuid' => 'varchar(36) PRIMARY KEY', 'language' => 'varchar(5)', 'source_model' => 'varchar(128)', 'source_model_uuid' => 'varchar(36)', 'source_model_attribute' => 'varchar(128)', 'translation' => 'text'])->execute();
$migrated = true;
try {
    (new m260929_120000_create_menu_tables(['db' => $app->db, 'compact' => true]))->up();
} catch (\Throwable $e) {
    $migrated = false;
}
check('existing translation table is left alone', true, $migrated);

$app = menuApp();

echo "\nMenu rules\n";
$menu = new Menu(['key' => 'Main Nav', 'systitle' => 'Main', 'max_depth' => 2]);
check('key with spaces/uppercase is invalid', false, $menu->validate());
$menu = new Menu(['key' => 'main', 'systitle' => 'Main', 'max_depth' => 6]);
check('depth above 5 is invalid', false, $menu->validate());
$menu = new Menu(['key' => 'main', 'systitle' => 'Main', 'max_depth' => 0]);
check('depth below 1 is invalid', false, $menu->validate());

$main = makeMenu('main');
check('uuid assigned', 36, strlen($main->uuid));
check('timestamps set', true, $main->updated > 0);
$dup = new Menu(['key' => 'main', 'systitle' => 'Other', 'max_depth' => 2]);
check('duplicate key is invalid', false, $dup->validate());

$main->key = 'renamed';
$main->save();
check('key is immutable after create', 'main', Menu::findOne($main->uuid)->key);

echo "\nVersion only moves forward\n";
$future = time() + 100;
Menu::updateAll(['updated' => $future], ['uuid' => $main->uuid]);
$main->refresh();
$main->systitle = 'Main (settings)';
check('settings save succeeds', true, $main->save());
check('settings save after a future saver bump still increases updated', $future + 1, (int)Menu::findOne($main->uuid)->updated);
$stale = Menu::findOne($main->uuid);
$bumped = time() + 500;
Menu::updateAll(['updated' => $bumped], ['uuid' => $main->uuid]);
$stale->systitle = 'Stale edit';
check('stale model saves', true, $stale->save());
check('stale save does not move updated backwards', $bumped + 1, (int)Menu::findOne($main->uuid)->updated);
check('in-memory updated shows the stored value', $bumped + 1, $stale->updated);
check('in-memory updated is not dirty after save', [], array_keys($stale->getDirtyAttributes()));
Menu::updateAll(['updated' => null], ['uuid' => $main->uuid]);
$nullMenu = Menu::findOne($main->uuid);
$nullMenu->systitle = 'Null version';
check('menu with NULL updated saves', true, $nullMenu->save());
check('NULL updated becomes a value', true, (int)Menu::findOne($main->uuid)->updated >= time() - 1);
$fresh = makeMenu('fresh-insert');
check('insert still stamps the current time', true, abs((int)$fresh->updated - time()) <= 1 && (int)$fresh->updated === (int)$fresh->created);
$fromZero = Menu::nextUpdated(0);
check('next version helper', [true, $future + 1], [abs($fromZero - time()) <= 1, Menu::nextUpdated($future)]);
$fresh->delete();

echo "\nForeign keys\n";
$dangling = false;
try {
    $app->db->createCommand()->insert('menu_item', ['uuid' => 'dangling-item', 'menu_uuid' => $main->uuid, 'parent_uuid' => 'no-such-parent', 'sort' => 0, 'target_type' => MenuItem::TARGET_NONE, 'new_window' => 0, 'state' => MenuItem::STATE_ONLINE])->execute();
} catch (\yii\db\IntegrityException $e) {
    $dangling = true;
}
check('harness enforces FKs: dangling parent_uuid is rejected', true, $dangling);

echo "\nItems\n";
$parent = makeItem($main, ['label' => 'Über uns', 'target_type' => MenuItem::TARGET_NONE, 'target_url' => null]);
makeItem($main, ['parent_uuid' => $parent->uuid, 'label' => 'Kontakt']);
check('items relation ordered', 2, count($main->items));
check('translation behavior attached', true, $parent->getBehavior('translation') !== null);

echo "\nDepth vs stored items\n";
$main->max_depth = 1;
check('depth below stored tree is invalid', false, $main->validate());
check('depth error message (de)', 'Dieses Menü enthält bereits Einträge in 2 Ebenen. Verschieben oder entfernen Sie diese, bevor Sie die Tiefe verringern.', $main->getFirstError('max_depth'));
$main->max_depth = 2;
check('depth equal to stored tree is valid', true, $main->validate());
$main->max_depth = 3;
check('depth above stored tree is valid', true, $main->validate());
$fresh = new Menu(['key' => 'fresh', 'systitle' => 'Fresh', 'max_depth' => 1]);
check('new menu with depth 1 is valid', true, $fresh->validate());

echo "\nKey rules apply on create only\n";
$legacy = makeMenu('legacy');
Yii::$app->db->createCommand()->update('menu', ['key' => 'Bad Key'], ['uuid' => $legacy->uuid])->execute();
$legacy = Menu::findOne($legacy->uuid);
$legacy->systitle = 'Legacy renamed';
check('existing menu with an invalid stored key still validates', true, $legacy->validate());
$legacy->key = 'main';
check('existing menu with an edited, duplicate key still validates (beforeSave resets it)', true, $legacy->validate());
$blank = makeMenu('blankkey');
$blank->key = '';
$blank->systitle = 'Blank renamed';
check('existing menu with an empty posted key still validates', true, $blank->validate());
check('...and saves', true, $blank->save());
check('...and keeps its key', ['blankkey', 'Blank renamed'], (function () use ($blank) {
    $stored = Menu::findOne($blank->uuid);
    return [$stored->key, $stored->systitle];
})());
$blank->systitle = '';
check('systitle stays required on update', false, $blank->validate());
$blank->delete();
$new = new Menu(['key' => '', 'systitle' => 'New', 'max_depth' => 2]);
check('key is still required on create', true, $new->validate() === false && $new->hasErrors('key'));
$legacy->delete();

echo "\nDeletion is atomic\n";
makeItem($main, ['label' => 'Tr']);
$trItem = MenuItem::find()->where(['menu_uuid' => $main->uuid])->one();
$trItem->setTranslations(['en' => ['label' => 'Tr en']]);
$trItem->save(false);
$trBefore = (int)CrelishTranslation::find()->count();
Yii::$app->db->pdo->exec("CREATE TRIGGER block_item_delete BEFORE DELETE ON menu_item BEGIN SELECT RAISE(ABORT, 'blocked'); END");
$failed = false;
try {
  $main->delete();
} catch (\Throwable $e) {
  $failed = true;
}
check('forced item-delete failure surfaces', true, $failed);
check('translations are rolled back', $trBefore, (int)CrelishTranslation::find()->count());
check('menu survives the failed delete', true, Menu::findOne($main->uuid) !== null);
Yii::$app->db->createCommand('DROP TRIGGER block_item_delete')->execute();

$itemsBefore = (int)MenuItem::find()->count();
Yii::$app->db->pdo->exec("CREATE TRIGGER block_menu_delete BEFORE DELETE ON menu BEGIN SELECT RAISE(ABORT, 'blocked'); END");
$failed = false;
try {
  $main->delete();
} catch (\Throwable $e) {
  $failed = true;
}
check('forced menu-row delete failure surfaces', true, $failed);
check('items survive a failed menu-row delete', $itemsBefore, (int)MenuItem::find()->count());
check('translations survive a failed menu-row delete', $trBefore, (int)CrelishTranslation::find()->count());
Yii::$app->db->createCommand('DROP TRIGGER block_menu_delete')->execute();

$main->delete();
check('deleting a menu deletes its items', 0, (int)MenuItem::find()->count());
check('deleting a menu deletes its translations', 0, (int)CrelishTranslation::find()->where(['source_model' => 'menu_item'])->count());

shortLinkDone();
