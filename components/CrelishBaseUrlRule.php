<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 20:17
 */

namespace giantbits\crelish\components;

use yii\helpers\Url;
use yii\web\UrlRuleInterface;
use yii\base\Object;

class CrelishBaseUrlRule extends Object implements UrlRuleInterface
{

  public function createUrl($manager, $route, $params)
  {
    if ($route === 'car/index') {
      if (isset($params['manufacturer'], $params['model'])) {
        return $params['manufacturer'] . '/' . $params['model'];
      } elseif (isset($params['manufacturer'])) {
        return $params['manufacturer'];
      }
    }
    return false;  // this rule does not apply
  }

  public function parseRequest($manager, $request)
  {
    $pathInfo = $request->getPathInfo();
    $pathInfo = str_replace('.html', '', $pathInfo);

    if (preg_match('%^(\w+)(/(\w+))?$%', $pathInfo, $matches)) {
      // check $matches[1] and $matches[3] to see
      // if they match a manufacturer and a model in the database
      // If so, set $params['manufacturer'] and/or $params['model']
      // and return ['car/index', $params]
    }

    $params = [
      'pathRequested' => (!empty($pathInfo)) ? $pathInfo : 'home'
    ];

    return ['crelish/frontend/run', $params];
    //return false;  // this rule does not apply
  }
}
