<?php
/**
 * Created by PhpStorm.
 * User: myrst
 * Date: 09.04.2018
 * Time: 16:01
 */

namespace giantbits\crelish\components;


use yii\helpers\Url;

class CrelishBaseHelper
{
  public static function urlFromSlug($slug, $params = [], $langCode = null)
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

    return Url::to($url, $params);
  }

  public static function currentUrl($params = [])
  {
    return Url::to(array_merge(['/' . \Yii::$app->controller->entryPoint['slug']], $params));
  }
}
