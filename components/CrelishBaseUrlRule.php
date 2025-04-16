<?php
/**
 *
 */

namespace giantbits\crelish\components;

use yii\base\InvalidConfigException;
use yii\web\UrlRuleInterface;

/**
 *
 */
class CrelishBaseUrlRule implements UrlRuleInterface
{

    /**
   * @throws InvalidConfigException
   */
  public function parseRequest($manager, $request)
  {
    $pathInfo = $request->getPathInfo();

    if (str_starts_with($pathInfo, 'crelish/')) {
      return false;
    }

    $langFreePath = $pathInfo;
    $langCode = '';

    if (isset(\Yii::$app->params['crelish']['langprefix']) && \Yii::$app->params['crelish']['langprefix']) {
      $pathInfoParts = explode("/", $pathInfo, 2);
      if (strlen($pathInfoParts[0]) == 2) {
        $langCode = $pathInfoParts[0];
        $langFreePath = '';
        if (count($pathInfoParts) > 1) {
          $langFreePath = $pathInfoParts[1];
        }
      }
    }

    if (empty($langFreePath)) {
      // If language prefix is enabled but no language is in the URL, redirect to default language
      if (isset(\Yii::$app->params['crelish']['langprefix']) && \Yii::$app->params['crelish']['langprefix'] && empty($langCode)) {
        $defaultLang = \Yii::$app->language;
        if (preg_match('/([a-z]{2})-[A-Z]{2}/', $defaultLang, $sub)) {
          $defaultLang = $sub[1];
        }
        header('Location: /' . $defaultLang);
        die();
      }
      
      // Set the language for the application if language code is available
      if (!empty($langCode)) {
        \Yii::$app->language = $langCode;
      }
      
      // No redirect needed, use home slug internally
      $params = array_merge($request->queryParams, [
        'pathRequested' => \Yii::$app->params['crelish']['entryPoint']['slug'],
        'language' => $langCode
      ]);
      
      return ['crelish/frontend/run', $params];
    }

    if ($langFreePath == 'crelish/' || $langFreePath == 'crelish') {
      header('Location: /crelish/dashboard/index');
      die();
    }

    if (strpos($langFreePath, '/') > 0) {
      $segments = explode('/', $langFreePath);
      $langFreePath = array_shift($segments);
      $additional = $segments;
    } else {
      $additional = [];
    }

    $params = array_merge($request->queryParams, [
      'pathRequested' => $langFreePath,
      'language' => $langCode,
      $additional
    ]);
    
    if (!empty($langCode)){
      \Yii::$app->language = $langCode;
    }

    return ['crelish/frontend/run', $params];
  }

  public function createUrl($manager, $route, $params): bool|string
  {

    if (str_starts_with($route, 'crelish/') && $route !== 'crelish/frontend/run') {
      return false;
    }

    if ($route != 'crelish/frontend/run') {
      return false;
    }

    $url = '';

    if (array_key_exists('language', $params) && !empty($params['languages'])) {
      $url .= $params['languages'];
    }

    if (array_key_exists('pathRequested', $params) && !empty($params['pathRequested'])) {
      if ($url != '') {
        $url .= '/';
      }

      $url .= $params['pathRequested'];
    }

    $paramsClean = $params;
    unset($params['language']);
    unset($paramsClean['pathRequested']);

    $paramsExposed = '?' . http_build_query($paramsClean);

    if(!empty($params['pathRequested'])) {
      return $params['pathRequested'] . $paramsExposed;
    }

    return $paramsExposed;
  }
  public function urlForSlug($slug, $langCode = null): string
  {
    $url = '/' . $slug;
    if (isset(\Yii::$app->params['crelish']['langprefix']) && \Yii::$app->params['crelish']['langprefix']) {
      if (empty($langCode)) {
        $langCode = \Yii::$app->language;
        if (preg_match('/([a-z]{2})-[A-Z]{2}/', $langCode, $sub)) {
          $langCode = $sub[1];
        }
      }
      $url = '/' . $langCode . $url;
    }
    return $url;
  }
}
