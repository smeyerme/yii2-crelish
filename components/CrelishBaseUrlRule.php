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
            if(!empty($params['language'])) {
                return $params['language'] . '/' . $route . '.html';
            } else {
                return $route . '.html';
            }
        }
        return Yii::$app->language . '/' . $route . '.html';
    }

    public function parseRequest($manager, $request)
    {
        $pathInfo = $request->getPathInfo();

        if(empty($pathInfo)) {
            $url=Yii::$app->urlManager->createAbsoluteUrl(['home', 'language'=>'']);
            Yii::$app->getResponse()->redirect($url);
        }

        if(strpos($pathInfo, '.html') === false && substr($pathInfo, -1) !== '/') {
            $pathInfo = $pathInfo . '/';
        } else {
            $pathInfo = str_replace('.html', '', $pathInfo);
        }

        if(substr_count($pathInfo, '/') === 0) {
            $langFreePath = $pathInfo;
            $langCode = '';
        } else {
            $langCode = substr($pathInfo, 0, strpos($pathInfo, '/'));
            $langFreePath = str_replace($langCode, $pathInfo, '');
        }

        $params = [
            'pathRequested' => $langFreePath,
            'language' => $langCode
        ];

        return ['crelish/frontend/run', $params];
    }
}
