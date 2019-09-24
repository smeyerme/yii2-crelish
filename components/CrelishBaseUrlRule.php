<?php
/**
 *
 */

namespace giantbits\crelish\components;

use Underscore\Types\Arrays;
//use Underscore\Types\Strings;
use yii\web\UrlRuleInterface;

/**
 *
 */
class CrelishBaseUrlRule implements UrlRuleInterface
{
  /**
   * [init description]
   * @return [type] [description]
   */
  public function init()
  {
    parent::init();
  }

  public function createUrl($manager, $route, $params)
  {

    $url = '';

    if ($route != 'crelish/frontend/run') {
      return FALSE;
    }

    if (array_key_exists('language', $params) && !empty($params['languages'])) {
      $url .= $params['languages'];
    }

    if (array_key_exists('pathRequested', $params) && !empty($params['pathRequested'])) {
      if ($url != '') {
        $url .= '/';
      }

      $url .= $params['pathRequested'];
    }

    $paramsClean = Arrays::remove($params, 'language');
    $paramsClean = Arrays::remove($paramsClean, 'pathRequested');

    $paramsExposed = '?';
    foreach ($paramsClean as $key => $value) {
      $paramsExposed .= $key . '=' . $value . '&';
    }
    $paramsExposed = rtrim($paramsExposed, '&');

    if (strpos($params['pathRequested'], ".html") === FALSE) {
      return $params['pathRequested'] . $paramsExposed;
    } else {
      return $params['pathRequested'] . $paramsExposed;
    }
  }

  public function parseRequest($manager, $request)
  {
    $pathInfo = $request->getPathInfo();

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

    if (!empty($langCode)) {
      \Yii::$app->language = $langCode;
    }

    return ['crelish/frontend/run', $params];
  }

  public function urlForSlug($slug, $langCode = null)
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
