<?php

namespace giantbits\crelish\components;

use Cocur\Slugify\Slugify;
use giantbits\crelish\components\shortlinks\ShortLinkTargetInterface;
use Yii;
use yii\db\BaseActiveRecord;

/**
 * Turns a link target (crelish record or plain URL) into a frontend URL.
 *
 * Content targets are resolved at request time, so links survive slug and
 * title changes. Resolution order: UrlTargetInterface, the deprecated
 * ShortLinkTargetInterface, detailPages config, then a page-style slug.
 */
class ContentUrlResolver
{
  public const TARGET_CONTENT = 'content';
  public const TARGET_URL = 'url';
  public const TARGET_NONE = 'none';

  public const STATE_ONLINE = 2;

  /** @var callable(string, string): ?object */
  private $recordFinder;

  public function __construct(?callable $recordFinder = null)
  {
    $this->recordFinder = $recordFinder ?? [self::class, 'findRecord'];
  }

  public static function findRecord(string $ctype, string $uuid): ?object
  {
    if (!CrelishModelResolver::modelExists($ctype)) {
      return null;
    }

    $class = CrelishModelResolver::getModelClass($ctype);

    return $class::find()->where(['uuid' => $uuid])->one();
  }

  /**
   * @return string|null URL of the target, null when it has none or cannot be reached
   */
  public function resolve(string $type, ?string $ctype, ?string $uuid, ?string $url, ?string $language, ?int $now = null): ?string
  {
    return match ($type) {
      self::TARGET_URL => ($url !== null && trim($url) !== '') ? trim($url) : null,
      self::TARGET_CONTENT => ($ctype && $uuid) ? $this->resolveContent($ctype, $uuid, $language, $now) : null,
      default => null,
    };
  }

  /**
   * @return string|null site-relative or absolute URL, null when the record cannot be reached
   */
  public function resolveContent(string $ctype, string $uuid, ?string $language = null, ?int $now = null): ?string
  {
    $record = ($this->recordFinder)($ctype, $uuid);

    if ($record === null || !self::isPublished($record, $now ?? time())) {
      return null;
    }

    $language = $language ?: self::currentLanguage();

    if ($record instanceof UrlTargetInterface) {
      return $record->getTargetUrl($language) ?: null;
    }

    if ($record instanceof ShortLinkTargetInterface) {
      return $record->getShortLinkUrl($language) ?: null;
    }

    $detailPage = self::detailPages()[$ctype] ?? null;

    if ($detailPage !== null) {
      $slug = Slugify::create()->slugify((string)self::attribute($record, 'systitle'));

      return CrelishBaseHelper::urlFromSlug($detailPage, [], $language) . '/' . self::attribute($record, 'uuid') . '/' . $slug;
    }

    $slug = self::attribute($record, 'slug');

    return $slug ? CrelishBaseHelper::urlFromSlug((string)$slug, [], $language) : null;
  }

  /**
   * Label a link to this record gets when the editor left it empty.
   */
  public function resolveLabel(string $ctype, string $uuid): ?string
  {
    $record = ($this->recordFinder)($ctype, $uuid);

    if ($record === null) {
      return null;
    }

    foreach (['navtitle', 'systitle'] as $attribute) {
      $value = trim((string)self::attribute($record, $attribute));

      if ($value !== '') {
        return $value;
      }
    }

    return null;
  }

  public function resolveTitle(string $ctype, string $uuid): ?string
  {
    $record = ($this->recordFinder)($ctype, $uuid);
    $title = $record === null ? '' : trim((string)self::attribute($record, 'systitle'));

    return $title !== '' ? $title : null;
  }

  /**
   * @return array<string,string> ctype => slug of the listing page that hosts its detail views
   */
  public static function detailPages(): array
  {
    $crelish = Yii::$app->params['crelish'] ?? [];
    $pages = $crelish['detailPages'] ?? $crelish['shortLinks']['detailPages'] ?? [];

    return is_array($pages) ? $pages : [];
  }

  public static function canResolveType(string $ctype): bool
  {
    if ($ctype === 'page' || isset(self::detailPages()[$ctype])) {
      return true;
    }

    try {
      $class = CrelishModelResolver::getModelClass($ctype);
    } catch (\Throwable) {
      return false;
    }

    return self::implementsTarget($class);
  }

  /**
   * @return string[] content types an editor can pick as a target
   */
  public static function resolvableTypes(): array
  {
    $types = array_merge(['page'], array_keys(self::detailPages()));

    try {
      foreach (CrelishModelResolver::getAllModels() as $ctype => $class) {
        if (self::implementsTarget($class)) {
          $types[] = $ctype;
        }
      }
    } catch (\Throwable $e) {
      Yii::warning('Content URL resolver: model discovery failed: ' . $e->getMessage(), 'crelish');
    }

    $types = array_values(array_unique($types));
    sort($types);

    return $types;
  }

  public static function currentLanguage(): string
  {
    return substr(Yii::$app->language, 0, 2);
  }

  private static function implementsTarget(string $class): bool
  {
    return is_subclass_of($class, UrlTargetInterface::class) || is_subclass_of($class, ShortLinkTargetInterface::class);
  }

  private static function isPublished(object $record, int $now): bool
  {
    $state = self::attribute($record, 'state');

    if ($state !== null && (int)$state !== self::STATE_ONLINE) {
      return false;
    }

    $from = self::timestamp(self::attribute($record, 'from'), false);
    $to = self::timestamp(self::attribute($record, 'to'), true);

    return ($from === null || $from <= $now) && ($to === null || $to >= $now);
  }

  private static function timestamp(mixed $value, bool $endOfDay): ?int
  {
    if ($value === null || $value === '' || $value === 0 || $value === '0') {
      return null;
    }

    if (is_numeric($value)) {
      return (int)$value;
    }

    $value = (string)$value;

    if (str_starts_with($value, '0000-00-00')) {
      return null;
    }

    if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      $value .= ' 23:59:59';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? null : $timestamp;
  }

  private static function attribute(object $record, string $name): mixed
  {
    if ($record instanceof BaseActiveRecord) {
      return $record->hasAttribute($name) ? $record->getAttribute($name) : null;
    }

    return $record->$name ?? null;
  }
}
