<?php

/**
 * Shared harness for the menu tests: the short link harness (fresh web app,
 * in-memory SQLite, check()/shortLinkDone()) plus the real menu migration.
 */

declare(strict_types=1);

require_once __DIR__ . '/../shortlink/bootstrap.php';

use giantbits\crelish\migrations\m260929_120000_create_menu_tables;
use giantbits\crelish\models\Menu;
use giantbits\crelish\models\MenuItem;

function menuApp(array $params = [], array $server = []): \yii\web\Application
{
    $app = shortLinkApp(array_replace_recursive(['languages' => ['de', 'en']], $params), $server);
    (new m260929_120000_create_menu_tables(['db' => $app->db, 'compact' => true]))->up();

    return $app;
}

function makeMenu(string $key, array $attributes = []): Menu
{
    $menu = new Menu();
    $menu->setAttributes(array_merge(['key' => $key, 'systitle' => ucfirst($key), 'max_depth' => 2, 'state' => Menu::STATE_ONLINE], $attributes), false);

    if (!$menu->save()) {
        throw new RuntimeException('menu not saved: ' . json_encode($menu->errors));
    }

    return $menu;
}

function makeItem(Menu $menu, array $attributes): MenuItem
{
    $item = new MenuItem();
    $item->setAttributes(array_merge([
        'menu_uuid' => $menu->uuid, 'parent_uuid' => null, 'sort' => 0, 'label' => null,
        'target_type' => MenuItem::TARGET_URL, 'target_url' => '/de/x', 'new_window' => 0, 'state' => MenuItem::STATE_ONLINE,
    ], $attributes), false);

    if (!$item->save(false)) {
        throw new RuntimeException('item not saved');
    }

    return $item;
}
