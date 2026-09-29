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

echo "\nItems\n";
$parent = makeItem($main, ['label' => 'Über uns', 'target_type' => MenuItem::TARGET_NONE, 'target_url' => null]);
makeItem($main, ['parent_uuid' => $parent->uuid, 'label' => 'Kontakt']);
check('items relation ordered', 2, count($main->items));
check('translation behavior attached', true, $parent->getBehavior('translation') !== null);

$main->delete();
check('deleting a menu deletes its items', 0, (int)MenuItem::find()->count());

shortLinkDone();
