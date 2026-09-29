<?php

namespace giantbits\crelish\components\menus;

use giantbits\crelish\components\ContentUrlResolver;
use giantbits\crelish\models\Menu;
use giantbits\crelish\models\MenuItem;
use Yii;
use yii\caching\TagDependency;

/**
 * Resolved menu trees for themes.
 *
 * tree() is cached per menu key and language; every write that can change a
 * menu (menu/item saves, any content save or delete) calls invalidate().
 */
class MenuService
{
  public const CACHE_TAG = 'crelish.menu';
  public const CACHE_TTL = 3600;

  private ContentUrlResolver $resolver;

  public function __construct(?ContentUrlResolver $resolver = null)
  {
    $this->resolver = $resolver ?? new ContentUrlResolver();
  }

  public static function invalidate(): void
  {
    if (Yii::$app === null || !Yii::$app->has('cache')) {
      return;
    }

    // A failing cache backend must never break the content or menu save that triggered this
    try {
      TagDependency::invalidate(Yii::$app->cache, self::CACHE_TAG);
    } catch (\Throwable $e) {
      Yii::error('Menu cache invalidation failed: ' . $e->getMessage(), 'crelish.menu');
    }
  }

  /**
   * Cached tree for the current language, without active flags.
   */
  public function tree(string $key): array
  {
    $language = ContentUrlResolver::currentLanguage();

    if (!Yii::$app->has('cache')) {
      return $this->build($key, $language);
    }

    return Yii::$app->cache->getOrSet(
      'crelish.menu.' . $key . '.' . $language,
      fn() => $this->build($key, $language),
      self::CACHE_TTL,
      new TagDependency(['tags' => [self::CACHE_TAG]])
    );
  }

  /**
   * Uncached tree. Labels come from the loaded items, whose translation
   * behavior already applied Yii::$app->language.
   */
  public function build(string $key, string $language): array
  {
    $menu = Menu::find()->where(['key' => $key])->one();

    if ($menu === null) {
      Yii::warning('Menu "' . $key . '" does not exist', 'crelish.menu');
      return [];
    }

    if ((int)$menu->state !== Menu::STATE_ONLINE) {
      return [];
    }

    $byParent = [];

    foreach ($menu->items as $item) {
      $byParent[(string)$item->parent_uuid][] = $item;
    }

    return $this->nodes($byParent, '', $language, 1, (int)$menu->max_depth, $key);
  }

  /**
   * @param array<string, MenuItem[]> $byParent
   */
  private function nodes(array $byParent, string $parent, string $language, int $depth, int $maxDepth, string $key): array
  {
    $nodes = [];

    foreach ($byParent[$parent] ?? [] as $item) {
      if ((int)$item->state !== MenuItem::STATE_ONLINE) {
        continue;
      }

      $url = null;

      if ($item->target_type === MenuItem::TARGET_CONTENT) {
        $url = $this->resolver->resolveContent((string)$item->target_ctype, (string)$item->target_uuid, $language);

        if ($url === null) {
          Yii::warning('Menu "' . $key . '": target ' . $item->target_ctype . '/' . $item->target_uuid . ' is unavailable, item ' . $item->uuid . ' skipped', 'crelish.menu');
          continue;
        }
      } elseif ($item->target_type === MenuItem::TARGET_URL) {
        $url = $this->resolver->resolve(MenuItem::TARGET_URL, null, null, $item->target_url, $language);

        if ($url === null) {
          continue;
        }
      }

      $children = $depth < $maxDepth
        ? $this->nodes($byParent, $item->uuid, $language, $depth + 1, $maxDepth, $key)
        : [];

      if ($item->target_type === MenuItem::TARGET_NONE && $children === []) {
        continue;
      }

      $label = trim((string)$item->label);

      if ($label === '' && $item->target_type === MenuItem::TARGET_CONTENT) {
        $label = (string)$this->resolver->resolveLabel((string)$item->target_ctype, (string)$item->target_uuid);
      }

      if ($label === '') {
        Yii::warning('Menu "' . $key . '": item ' . $item->uuid . ' has no label, skipped', 'crelish.menu');
        continue;
      }

      $nodes[] = [
        'uuid' => $item->uuid,
        'label' => $label,
        'url' => $url,
        'type' => $item->target_type,
        'targetUuid' => $item->target_type === MenuItem::TARGET_CONTENT ? $item->target_uuid : null,
        'external' => $url !== null && self::isExternal($url),
        'newWindow' => (bool)$item->new_window,
        'active' => false,
        'activeTrail' => false,
        'children' => $children,
      ];
    }

    return $nodes;
  }

  private static function isExternal(string $url): bool
  {
    if (!preg_match('~^https?://~i', $url)) {
      return false;
    }

    $host = parse_url($url, PHP_URL_HOST);
    $request = Yii::$app->request;
    $current = $request instanceof \yii\web\Request ? $request->getHostName() : null;

    return $current === null || strcasecmp((string)$host, $current) !== 0;
  }

  /**
   * Tree for themes, with active flags for the current request. Never throws.
   */
  public function get(string $key): array
  {
    try {
      $tree = $this->tree($key);
      $request = Yii::$app->request;

      if (!$request instanceof \yii\web\Request || $tree === []) {
        return $tree;
      }

      $languages = Yii::$app->params['crelish']['languages'] ?? [];
      $roots = array_merge(['/'], array_map(fn($lang) => '/' . $lang, $languages));

      return MenuActiveState::apply($tree, self::currentUuid(), '/' . $request->getPathInfo(), $request->getHostName(), $roots);
    } catch (\Throwable $e) {
      Yii::error('Menu "' . $key . '" could not be rendered: ' . $e->getMessage(), 'crelish.menu');

      return [];
    }
  }

  private static function currentUuid(): ?string
  {
    $content = Yii::$app->params['content'] ?? null;

    if (is_array($content)) {
      return isset($content['uuid']) ? (string)$content['uuid'] : null;
    }

    if (is_object($content) && isset($content->uuid)) {
      return (string)$content->uuid;
    }

    return null;
  }
}
