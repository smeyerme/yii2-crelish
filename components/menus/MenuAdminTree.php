<?php

namespace giantbits\crelish\components\menus;

use giantbits\crelish\components\ContentUrlResolver;
use giantbits\crelish\models\Menu;
use giantbits\crelish\models\MenuItem;
use Yii;

/**
 * JSON the tree editor loads: menu meta, languages and a flat item list
 * with the raw default-language label, all translations and target status.
 */
final class MenuAdminTree
{
  /**
   * @return string[] configured languages, default first
   */
  public static function languages(): array
  {
    $languages = Yii::$app->params['crelish']['languages'] ?? [];

    return $languages ?: [ContentUrlResolver::currentLanguage()];
  }

  public static function build(Menu $menu, ?ContentUrlResolver $resolver = null): array
  {
    $resolver ??= new ContentUrlResolver();
    $languages = self::languages();
    $default = $languages[0];
    $items = MenuItem::find()
      ->where(['menu_uuid' => $menu->uuid])
      ->orderBy(['parent_uuid' => SORT_ASC, 'sort' => SORT_ASC, 'uuid' => SORT_ASC])
      ->all();
    $rows = [];

    foreach ($items as $item) {
      $translations = $item->loadAllTranslations()['label'] ?? [];
      $i18n = [];

      foreach (array_slice($languages, 1) as $language) {
        $i18n[$language] = (string)($translations[$language] ?? '');
      }

      $isContent = $item->target_type === MenuItem::TARGET_CONTENT;

      $rows[] = [
        'uuid' => $item->uuid,
        'parentRef' => $item->parent_uuid,
        'sort' => (int)$item->sort,
        // The translation behavior swapped in the admin language; the column holds the default language
        'label' => (string)$item->getOldAttribute('label'),
        'i18n' => $i18n,
        'target_type' => $item->target_type,
        'target_ctype' => $item->target_ctype,
        'target_uuid' => $item->target_uuid,
        'target_url' => $item->target_url,
        'new_window' => (bool)$item->new_window,
        'state' => (int)$item->state,
        'targetTitle' => $isContent ? $resolver->resolveTitle((string)$item->target_ctype, (string)$item->target_uuid) : null,
        'targetAvailable' => !$isContent || $resolver->resolveContent((string)$item->target_ctype, (string)$item->target_uuid, $default) !== null,
        'fallbackLabel' => $isContent ? $resolver->resolveLabel((string)$item->target_ctype, (string)$item->target_uuid) : null,
      ];
    }

    return [
      'menu' => [
        'uuid' => $menu->uuid,
        'key' => $menu->key,
        'systitle' => $menu->systitle,
        'max_depth' => (int)$menu->max_depth,
        'updated' => (int)$menu->updated,
      ],
      'languages' => $languages,
      'defaultLanguage' => $default,
      'items' => $rows,
    ];
  }
}
