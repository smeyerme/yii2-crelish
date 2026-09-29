<?php

namespace giantbits\crelish\components;

use Yii;

/**
 * Record search behind the link target pickers (menus, short links).
 */
final class ContentTargetSearch
{
  public const LIMIT = 20;

  /**
   * @param callable(string): string|null $classFor ctype => ActiveRecord class
   * @return array<int, array{uuid: string, title: string}>
   */
  public static function search(string $ctype, string $q, ?callable $classFor = null): array
  {
    $q = trim($q);

    if (mb_strlen($q) < 2 || !in_array($ctype, ContentUrlResolver::resolvableTypes(), true)) {
      return [];
    }

    try {
      $class = ($classFor ?? [CrelishModelResolver::class, 'getModelClass'])($ctype);

      $records = $class::find()
        ->select(['uuid', 'systitle'])
        ->where(['like', 'systitle', $q])
        ->orderBy(['systitle' => SORT_ASC])
        ->limit(self::LIMIT)
        ->asArray()
        ->all();
    } catch (\Throwable $e) {
      Yii::warning('Target search failed for ctype "' . $ctype . '": ' . $e->getMessage(), 'crelish');

      return [];
    }

    return array_map(fn(array $record) => ['uuid' => (string)$record['uuid'], 'title' => (string)$record['systitle']], $records);
  }

  /**
   * @return array<int, array{ctype: string, label: string}>
   */
  public static function types(): array
  {
    return array_map(fn(string $ctype) => ['ctype' => $ctype, 'label' => self::typeLabel($ctype)], ContentUrlResolver::resolvableTypes());
  }

  private static function typeLabel(string $ctype): string
  {
    $file = Yii::getAlias('@app/workspace/elements/' . $ctype . '.json', false);

    if ($file && is_file($file)) {
      $definition = json_decode((string)file_get_contents($file), true);

      if (!empty($definition['label'])) {
        return (string)$definition['label'];
      }
    }

    return ucfirst($ctype);
  }
}
