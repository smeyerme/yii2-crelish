<?php

namespace giantbits\crelish\components\shortlinks;

use giantbits\crelish\components\ContentUrlResolver;
use giantbits\crelish\models\ShortLink;
use Yii;

/**
 * Decides where a short link sends a visitor right now.
 *
 * Content targets are resolved at redirect time, so links survive slug and
 * title changes. Content resolution is delegated to ContentUrlResolver; this class adds link state, language negotiation and fallbacks.
 */
class ShortLinkResolver
{
  private ContentUrlResolver $content;

  public function __construct(?callable $recordFinder = null)
  {
    $this->content = new ContentUrlResolver($recordFinder);
  }

  public static function findRecord(string $ctype, string $uuid): ?object
  {
    return ContentUrlResolver::findRecord($ctype, $uuid);
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

    $url = $this->content->resolveContent(
      (string)$link->target_ctype,
      (string)$link->target_uuid,
      $this->language($link, $acceptLanguage),
      $now
    );

    return $url !== null ? ShortLinkConfig::absolute($url) : null;
  }

  public function fallbackFor(ShortLink $link): string
  {
    return $link->fallback_url ?: ShortLinkConfig::siteFallbackUrl();
  }

  public static function canResolveType(string $ctype): bool
  {
    return ContentUrlResolver::canResolveType($ctype);
  }

  /**
   * @return string[] content types an editor can pick as a target
   */
  public static function resolvableTypes(): array
  {
    return ContentUrlResolver::resolvableTypes();
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
}
