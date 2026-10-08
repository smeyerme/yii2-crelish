<?php

namespace giantbits\crelish\components\Analytics;

use yii\base\BaseObject;
use yii\web\UrlManager;
use yii\web\UrlRuleInterface;

/**
 * Routes the browser confirmation endpoint (BrowserConfirmation::path()).
 *
 * Must run before CrelishBaseUrlRule, which would otherwise treat the path as
 * a page slug.
 */
class PageStateUrlRule extends BaseObject implements UrlRuleInterface
{
  public const ROUTE = 'crelish/page-state/index';

  public static function register(UrlManager $manager): void
  {
    if (!BrowserConfirmation::isEnabled()) {
      return;
    }

    $manager->addRules([['class' => self::class]], false);
  }

  public function parseRequest($manager, $request)
  {
    if (!BrowserConfirmation::isEnabled()) {
      return false;
    }

    return trim($request->getPathInfo(), '/') === BrowserConfirmation::path() ? [self::ROUTE, []] : false;
  }

  public function createUrl($manager, $route, $params)
  {
    return false;
  }
}
