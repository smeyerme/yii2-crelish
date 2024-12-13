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
	  
    $paramsExposed = '?';
    foreach ($paramsClean as $key => $value) {
      $paramsExposed .= $key . '=' . $value . '&';
    }
    $paramsExposed = rtrim($paramsExposed, '&');

    return $params['pathRequested'] . $paramsExposed;
  }

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
      header('Location: ' . $this->urlForSlug(\Yii::$app->params['crelish']['entryPoint']['slug'], $langCode));
      die();
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
