<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 20:17
 */

namespace giantbits\crelish\components;

use yii;
use yii\web\UrlRuleInterface;
use yii\base\Object;

class CrelishBaseUrlRule extends Object implements UrlRuleInterface
{

    public function init()
    {
        parent::init();
    }

    public function createUrl($manager, $route, $params)
    {
        if (isset($params['language'])) {
            return $params['language'] . '/' . $route . '.html';
        }
        return Yii::$app->language . '/' . $route . '.html';
    }

    public function parseRequest($manager, $request)
    {
        $pathInfo = $request->getPathInfo();

        if(strpos($pathInfo, '.html') === false) {
            $pathInfo = $pathInfo . '/';
        } else {
            $pathInfo = str_replace('.html', '', $pathInfo);
        }

        $langCode = substr($pathInfo, 0, strpos($pathInfo, '/'));
        $langFreePath = substr($pathInfo, strpos($pathInfo, '/') + 1);

        $params = [
            'pathRequested' => (!empty($langFreePath)) ? $langFreePath : 'home',
            'language' => $langCode
        ];

        return ['crelish/frontend/run', $params];
    }
}
