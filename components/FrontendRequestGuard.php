<?php

namespace giantbits\crelish\components;

/**
 * Request checks for the frontend controller.
 *
 * crelish/frontend/run is internal: CrelishBaseUrlRule resolves a slug and dispatches
 * to it. Reached directly, its ?language= parameter went unchecked into
 * Yii::$app->language, and any string made the page fail ("Invalid language code").
 */
final class FrontendRequestGuard
{
  private const RUN_ROUTE = 'crelish/frontend/run';

  // de, en, en-US, de_CH, zh-Hant: what a language code can look like, nothing else
  private const LANGUAGE_PATTERN = '/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?$/';

  /** True when the request addresses the internal route itself instead of a page. */
  public static function isDirectRunRequest(string $pathInfo): bool
  {
    $path = strtolower(trim($pathInfo, '/'));
    return $path === self::RUN_ROUTE || str_starts_with($path, self::RUN_ROUTE . '/');
  }

  /**
   * The language from the query when it is a well-formed code the site supports, otherwise null.
   *
   * @param mixed $value the raw query value
   * @param callable(string): bool $isSupported
   */
  public static function queryLanguage(mixed $value, callable $isSupported): ?string
  {
    if (!is_string($value) || !preg_match(self::LANGUAGE_PATTERN, $value)) {
      return null;
    }
    return $isSupported($value) ? $value : null;
  }
}
