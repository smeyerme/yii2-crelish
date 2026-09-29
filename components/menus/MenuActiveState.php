<?php

namespace giantbits\crelish\components\menus;

/**
 * Marks the items that lead to the current page.
 *
 * An item is active when it targets the current record, or when its URL is
 * the longest same-host path prefix of the current path. Site roots (/ and
 * /<lang>) only match exactly. Ancestors of active items get activeTrail.
 */
final class MenuActiveState
{
  /**
   * @param string[] $rootPaths paths that must match exactly, e.g. ['/', '/de', '/en']
   */
  public static function apply(array $tree, ?string $currentUuid, string $currentPath, ?string $host, array $rootPaths): array
  {
    $current = self::normalize($currentPath);
    $best = self::longestMatch($tree, $current, $host, $rootPaths);

    return self::mark($tree, $currentUuid, $current, $host, $rootPaths, $best);
  }

  private static function mark(array $nodes, ?string $uuid, string $current, ?string $host, array $roots, int $best): array
  {
    foreach ($nodes as $i => $node) {
      $node['children'] = self::mark($node['children'], $uuid, $current, $host, $roots, $best);

      $matchLength = self::matchLength($node['url'], $current, $host, $roots);
      $node['active'] = ($uuid !== null && $node['targetUuid'] === $uuid) || ($best > 0 && $matchLength === $best);
      $node['activeTrail'] = false;

      foreach ($node['children'] as $child) {
        if ($child['active'] || $child['activeTrail']) {
          $node['activeTrail'] = true;
          break;
        }
      }

      $nodes[$i] = $node;
    }

    return $nodes;
  }

  private static function longestMatch(array $nodes, string $current, ?string $host, array $roots): int
  {
    $best = 0;

    foreach ($nodes as $node) {
      $best = max($best, self::matchLength($node['url'], $current, $host, $roots), self::longestMatch($node['children'], $current, $host, $roots));
    }

    return $best;
  }

  /**
   * @return int length of the item path when it matches the current path, 0 otherwise
   */
  private static function matchLength(?string $url, string $current, ?string $host, array $roots): int
  {
    $path = self::localPath($url, $host);

    if ($path === null) {
      return 0;
    }

    if ($path === $current) {
      return strlen($path);
    }

    $isRoot = in_array($path, array_map([self::class, 'normalize'], $roots), true);

    return !$isRoot && str_starts_with($current, $path . '/') ? strlen($path) : 0;
  }

  /**
   * @return string|null normalized path when the URL points at this site
   */
  private static function localPath(?string $url, ?string $host): ?string
  {
    if ($url === null || $url === '') {
      return null;
    }

    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
      return self::normalize((string)parse_url($url, PHP_URL_PATH));
    }

    if (preg_match('~^https?://~i', $url) && $host !== null && strcasecmp((string)parse_url($url, PHP_URL_HOST), $host) === 0) {
      return self::normalize((string)(parse_url($url, PHP_URL_PATH) ?: '/'));
    }

    return null;
  }

  private static function normalize(string $path): string
  {
    $path = '/' . ltrim($path, '/');

    return $path === '/' ? '/' : rtrim($path, '/');
  }
}
