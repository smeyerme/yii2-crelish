<?php

namespace giantbits\crelish\components\shortlinks;

use giantbits\crelish\components\CrelishBaseHelper;
use Yii;

/**
 * Reads the short link settings from params['crelish']['shortLinks'].
 */
final class ShortLinkConfig
{
  public const DEFAULTS = [
    'enabled' => false,
    'prefix' => 'go',
    'shortHost' => null,
    'siteUrl' => null,
    'fallbackUrl' => null,
    'detailPages' => [],
    'qrLogo' => null,
    'reservedCodes' => [],
  ];

  public const BUILTIN_RESERVED = ['crelish', 'api', 'crelish-api', 'site', 'sitemap', 'robots', 'favicon', 'assets', 'q', 'document'];

  public static function all(): array
  {
    $config = Yii::$app->params['crelish']['shortLinks'] ?? [];

    return array_merge(self::DEFAULTS, is_array($config) ? $config : []);
  }

  public static function isEnabled(): bool
  {
    return (bool)self::all()['enabled'];
  }

  public static function prefix(): string
  {
    return strtolower(trim((string)self::all()['prefix'], '/'));
  }

  public static function shortHost(): ?string
  {
    $host = self::all()['shortHost'];

    return $host ? strtolower((string)$host) : null;
  }

  /**
   * Absolute base URL of the main site, without trailing slash
   */
  public static function siteUrl(): string
  {
    $url = self::all()['siteUrl'] ?: Yii::$app->request->hostInfo;

    return rtrim((string)$url, '/');
  }

  /**
   * Absolute URL of the site's home page in the default language
   */
  public static function homeUrl(): string
  {
    $slug = Yii::$app->params['crelish']['entryPoint']['slug'] ?? 'home';

    return self::absolute(CrelishBaseHelper::urlFromSlug($slug));
  }

  public static function siteFallbackUrl(): string
  {
    $url = self::all()['fallbackUrl'];

    return $url ? self::absolute((string)$url) : self::homeUrl();
  }

  /**
   * Make a site-relative URL absolute on the main site; absolute URLs pass through
   */
  public static function absolute(string $url): string
  {
    if (str_starts_with($url, '//')) {
      return 'https:' . $url;
    }

    if (str_starts_with($url, '/')) {
      return self::siteUrl() . $url;
    }

    return $url;
  }

  /**
   * @return array<string,string> ctype => slug of the listing page that hosts its detail views
   */
  public static function detailPages(): array
  {
    $pages = self::all()['detailPages'];

    return is_array($pages) ? $pages : [];
  }

  public static function qrLogoPath(): ?string
  {
    $path = self::all()['qrLogo'];

    return $path ? Yii::getAlias((string)$path) : null;
  }

  /**
   * @return string[] lowercase codes that can never be used
   */
  public static function reservedCodes(): array
  {
    $custom = array_map(static fn($code) => strtolower((string)$code), (array)self::all()['reservedCodes']);

    return array_values(array_unique(array_merge(self::BUILTIN_RESERVED, [self::prefix()], $custom)));
  }
}
