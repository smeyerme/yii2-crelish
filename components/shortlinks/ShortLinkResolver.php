<?php

namespace giantbits\crelish\components\shortlinks;

use Cocur\Slugify\Slugify;
use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\CrelishModelResolver;
use giantbits\crelish\models\ShortLink;
use Yii;
use yii\db\BaseActiveRecord;

/**
 * Decides where a short link sends a visitor right now.
 *
 * Content targets are resolved at redirect time, so links survive slug and
 * title changes. Resolution order: ShortLinkTargetInterface, detailPages
 * config, then a page-style slug attribute.
 */
class ShortLinkResolver
{
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

  public function resolve(ShortLink $link, ?string $acceptLanguage = null, ?int $now = null): ResolveResult
  {
    if (!$link->isLive($now)) {
      return new ResolveResult($this->fallbackFor($link), ResolveResult::REASON_INACTIVE);
    }

    if ($link->target_type === ShortLink::TARGET_URL) {
      return new ResolveResult((string)$link->target_url, ResolveResult::REASON_OK);
    }

    $url = $this->resolveContent($link, $acceptLanguage, $now);

    return $url !== null
      ? new ResolveResult($url, ResolveResult::REASON_OK)
      : new ResolveResult($this->fallbackFor($link), ResolveResult::REASON_BROKEN);
  }

  /**
   * @return string|null absolute URL of the content target, null when it cannot be reached
   */
  public function resolveContent(ShortLink $link, ?string $acceptLanguage = null, ?int $now = null): ?string
  {
    if (empty($link->target_ctype) || empty($link->target_uuid)) {
      return null;
    }

    $record = ($this->recordFinder)($link->target_ctype, $link->target_uuid);

    if ($record === null || !self::isPublished($record, $now ?? time())) {
      return null;
    }

    $language = $this->language($link, $acceptLanguage);

    if ($record instanceof ShortLinkTargetInterface) {
      $url = $record->getShortLinkUrl($language);
      return $url ? ShortLinkConfig::absolute($url) : null;
    }

    $detailPage = ShortLinkConfig::detailPages()[$link->target_ctype] ?? null;

    if ($detailPage !== null) {
      $slug = Slugify::create()->slugify((string)self::attribute($record, 'systitle'));

      return ShortLinkConfig::absolute(
        CrelishBaseHelper::urlFromSlug($detailPage, [], $language) . '/' . self::attribute($record, 'uuid') . '/' . $slug
      );
    }

    $slug = self::attribute($record, 'slug');

    return $slug ? ShortLinkConfig::absolute(CrelishBaseHelper::urlFromSlug((string)$slug, [], $language)) : null;
  }

  public function fallbackFor(ShortLink $link): string
  {
    return $link->fallback_url ?: ShortLinkConfig::siteFallbackUrl();
  }

  public static function canResolveType(string $ctype): bool
  {
    if ($ctype === 'page' || isset(ShortLinkConfig::detailPages()[$ctype])) {
      return true;
    }

    try {
      $class = CrelishModelResolver::getModelClass($ctype);
    } catch (\Throwable) {
      return false;
    }

    return is_subclass_of($class, ShortLinkTargetInterface::class);
  }

  /**
   * @return string[] content types an editor can pick as a target
   */
  public static function resolvableTypes(): array
  {
    $types = array_merge(['page'], array_keys(ShortLinkConfig::detailPages()));

    try {
      foreach (CrelishModelResolver::getAllModels() as $ctype => $class) {
        if (is_subclass_of($class, ShortLinkTargetInterface::class)) {
          $types[] = $ctype;
        }
      }
    } catch (\Throwable $e) {
      Yii::warning('Short links: model discovery failed: ' . $e->getMessage(), 'shortlink');
    }

    $types = array_values(array_unique($types));
    sort($types);

    return $types;
  }

  private function language(ShortLink $link, ?string $acceptLanguage): string
  {
    if (!empty($link->target_language)) {
      return (string)$link->target_language;
    }

    $supported = Yii::$app->params['crelish']['languages'] ?? [];

    foreach (self::acceptedLanguages($acceptLanguage) as $code) {
      if (in_array($code, $supported, true)) {
        return $code;
      }
    }

    return substr(Yii::$app->language, 0, 2);
  }

  /**
   * @return string[] two-letter codes from an Accept-Language header, best first
   */
  private static function acceptedLanguages(?string $header): array
  {
    $weighted = [];

    foreach (explode(',', (string)$header) as $index => $part) {
      $pieces = explode(';', trim($part));
      $code = strtolower(substr(trim($pieces[0]), 0, 2));

      if (!preg_match('/^[a-z]{2}$/', $code)) {
        continue;
      }

      $quality = 1.0;
      foreach (array_slice($pieces, 1) as $parameter) {
        if (preg_match('/^\s*q=([0-9.]+)\s*$/', $parameter, $matches)) {
          $quality = (float)$matches[1];
        }
      }

      $weighted[] = [$code, $quality, $index];
    }

    usort($weighted, static fn(array $a, array $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

    return array_values(array_unique(array_column($weighted, 0)));
  }

  private static function isPublished(object $record, int $now): bool
  {
    $state = self::attribute($record, 'state');

    if ($state !== null && (int)$state !== ShortLink::STATE_ONLINE) {
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
