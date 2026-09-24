<?php

namespace giantbits\crelish\components\shortlinks;

use yii\base\BaseObject;
use yii\web\UrlManager;
use yii\web\UrlRuleInterface;

/**
 * Routes /<prefix>/<code>[/q] (and /<code>[/q] on a dedicated short host) to
 * the short link redirect. Matching is case-insensitive because QR codes carry
 * the URL in uppercase.
 *
 * Must run before CrelishBaseUrlRule, which would otherwise treat the prefix
 * as a page slug or language code.
 */
class ShortLinkUrlRule extends BaseObject implements UrlRuleInterface
{
  public const ROUTE = 'crelish/short-link-redirect/index';
  public const HOME_ROUTE = 'crelish/short-link-redirect/home';

  public static function register(UrlManager $manager): void
  {
    if (!ShortLinkConfig::isEnabled()) {
      return;
    }

    $manager->addRules([['class' => self::class]], false);
  }

  public function parseRequest($manager, $request)
  {
    $path = trim($request->getPathInfo(), '/');
    $shortHost = ShortLinkConfig::shortHost();

    if ($shortHost !== null && strtolower((string)$request->getHostName()) === $shortHost) {
      $params = $this->match($path);

      return $params !== null ? [self::ROUTE, $params] : [self::HOME_ROUTE, []];
    }

    $prefix = ShortLinkConfig::prefix();

    if (!str_starts_with(strtolower($path) . '/', $prefix . '/')) {
      return false;
    }

    $params = $this->match(substr($path, strlen($prefix) + 1));

    return $params !== null ? [self::ROUTE, $params] : false;
  }

  public function createUrl($manager, $route, $params)
  {
    return false;
  }

  /**
   * @return array{code: string, scan: int}|null
   */
  private function match(string $rest): ?array
  {
    if (!preg_match('~^([a-z0-9-]{2,64})(/q)?/?$~i', $rest, $matches)) {
      return null;
    }

    return ['code' => strtolower($matches[1]), 'scan' => empty($matches[2]) ? 0 : 1];
  }
}
