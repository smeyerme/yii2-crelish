<?php

namespace giantbits\crelish\components\menus;

use giantbits\crelish\components\ContentUrlResolver;
use giantbits\crelish\models\Menu;
use giantbits\crelish\models\MenuItem;
use Yii;
use yii\base\Event;
use yii\caching\TagDependency;
use yii\db\Connection;

/**
 * Resolved menu trees for themes.
 *
 * tree() is cached per menu key and full locale (Yii::$app->language); every write that can change a
 * menu (menu/item saves, any content save or delete) calls invalidate(). Menu and item hooks use
 * invalidateAfterCommit(), so a concurrent read cannot re-cache the old tree before the write is visible.
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
   * Invalidates now, or, while a transaction is open on $db, once the outermost transaction commits
   * (Connection::EVENT_COMMIT_TRANSACTION fires only at level 0). A rollback drops the pending
   * invalidation: nothing changed, so the cached trees are still right.
   */
  public static function invalidateAfterCommit(?Connection $db = null): void
  {
    $db ??= Yii::$app?->has('db') ? Yii::$app->db : null;

    if ($db === null || $db->getTransaction() === null) {
      self::invalidate();
      return;
    }

    $onCommit = [self::class, 'onCommit'];
    $onRollBack = [self::class, 'onRollBack'];
    // off() first, so several writes in one transaction register the handler once
    $db->off(Connection::EVENT_COMMIT_TRANSACTION, $onCommit);
    $db->off(Connection::EVENT_ROLLBACK_TRANSACTION, $onRollBack);
    $db->on(Connection::EVENT_COMMIT_TRANSACTION, $onCommit);
    $db->on(Connection::EVENT_ROLLBACK_TRANSACTION, $onRollBack);
  }

  /**
   * @internal
   */
  public static function onCommit(Event $event): void
  {
    self::onRollBack($event);
    self::invalidate();
  }

  /**
   * @internal
   */
  public static function onRollBack(Event $event): void
  {
    $event->sender->off(Connection::EVENT_COMMIT_TRANSACTION, [self::class, 'onCommit']);
    $event->sender->off(Connection::EVENT_ROLLBACK_TRANSACTION, [self::class, 'onRollBack']);
  }

  /**
   * Cached tree for the current language, without the request-dependent
   * flags: external, active and activeTrail are false here; get() sets them.
   *
   * Keyed by the full Yii::$app->language (e.g. de-CH), because item labels
   * are translated by that full locale; content URLs use the two-letter code.
   */
  public function tree(string $key): array
  {
    $language = ContentUrlResolver::currentLanguage();

    if (!Yii::$app->has('cache')) {
      return $this->build($key, $language);
    }

    return Yii::$app->cache->getOrSet(
      'crelish.menu.' . $key . '.' . Yii::$app->language,
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
        'external' => false,
        'newWindow' => (bool)$item->new_window,
        'active' => false,
        'activeTrail' => false,
        'children' => $children,
      ];
    }

    return $nodes;
  }

  /**
   * Sets external on every node: an absolute http(s) URL to a host other
   * than $host (any absolute URL when there is no request host).
   */
  private static function markExternal(array $nodes, ?string $host): array
  {
    foreach ($nodes as $i => $node) {
      $url = (string)$node['url'];
      $nodes[$i]['external'] = preg_match('~^https?://~i', $url) === 1
        && ($host === null || strcasecmp((string)parse_url($url, PHP_URL_HOST), $host) !== 0);
      $nodes[$i]['children'] = self::markExternal($node['children'], $host);
    }

    return $nodes;
  }

  /**
   * Tree for themes, with the request-dependent flags (external, active,
   * activeTrail) for the current request. Never throws.
   */
  public function get(string $key): array
  {
    try {
      $tree = $this->tree($key);
      $request = Yii::$app->request;
      $isWeb = $request instanceof \yii\web\Request;
      $tree = self::markExternal($tree, $isWeb ? $request->getHostName() : null);

      if (!$isWeb || $tree === []) {
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
