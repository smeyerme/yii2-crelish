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
      return false;
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

    if (strpos($params['pathRequested'], ".html") === false) {
      return $params['pathRequested'] . '.html' . $paramsExposed;
    } else {
      return $params['pathRequested'] . $paramsExposed;
    }


  }

  public function parseRequest($manager, $request)
  {
    $pathInfo = $request->getPathInfo();

    if (empty($pathInfo)) {
      header('Location: /home.html');
      die();
    }

    if (strpos($pathInfo, '.html') === false) {
      if (substr($pathInfo, -1) !== "/") {
        header('Location: /' . $pathInfo . '.html');
        die();
      } else {
        $pathInfo = $pathInfo;
      }
    } else {
      $pathInfo = str_replace('.html', '', $pathInfo);
    }

    // Todo: Language handling.
    /*
    if (substr_count($pathInfo, '/') === 0) {
      $langFreePath = $pathInfo;
      $langCode = '';
    } else {
      $langCode = substr($pathInfo, 0, strpos($pathInfo, '/'));
      $langFreePath = str_replace($langCode . "/", '', $pathInfo);
    }*/
    $langFreePath = $pathInfo;
    $langCode = '';

    $params = array_merge($request->queryParams, [
      'pathRequested' => $langFreePath,
      'language' => $langCode
    ]);

    if (!empty($langCode)) {
      \Yii::$app->language = $langCode;
    }

    return ['crelish/frontend/run', $params];
  }
}
