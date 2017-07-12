<?php

namespace giantbits\crelish;

use giantbits\crelish\components\CrelishI18nEventHandler;
use \yii\base\BootstrapInterface;

class Bootstrap implements BootstrapInterface
{
    /** @param \yii\web\Application $app */
    public function bootstrap($app)
    {

        if ($app instanceof \yii\web\Application) {

            \Yii::$app->setComponents([
                'user' => [
                    'class' => 'giantbits\crelish\components\CrelishUser',
                    'identityClass' => 'giantbits\crelish\components\CrelishUser',
                    'enableAutoLogin' => TRUE,
                ],
                'defaultRoute' => 'frontend/index',
                'view' => [
                    'class' => 'yii\web\View',
                    'renderers' => [
                        'twig' => [
                            'class' => 'yii\twig\ViewRenderer',
                            'cachePath' => '@runtime/Twig/cache',
                            'options' => [
                                'auto_reload' => TRUE,
                            ],
                            'globals' => [
                                'html' => ['class' => '\yii\helpers\Html']
                            ],
                            'functions' => array(
                                't' => 'Yii::t',
                            )
                        ]
                    ]
                ],
                'urlManager' => [
                    'class' => 'yii\web\UrlManager',
                    'enablePrettyUrl' => TRUE,
                    'showScriptName' => FALSE,
                    'enableStrictParsing' => TRUE,
                    'suffix' => '.html',
                    'rules' => [],
                ],
                'i18n' => [
                    'class' => 'yii\i18n\I18N',
                    'translations' => [
                        'crelish*' => [
                            'class' => 'yii\i18n\PhpMessageSource',
                            'basePath' => '@app/messages',
                            'sourceLanguage' => 'en-US',
                            'fileMap' => [
                                'app' => 'app.php',
                                'app.error' => 'error.php',
                                'crelish' => 'crelish.php'
                            ],
                            'on missingTranslation' => [
                                CrelishI18nEventHandler::class,
                                'handleMissingTranslation'
                            ]
                        ],
                    ],
                ]
            ]);

            $app->getUrlManager()->addRules([
                [
                    'class' => 'yii\web\UrlRule',
                    'pattern' => 'crelish',
                    'route' => 'crelish/dahboard/index'
                ],
                [
                    'class' => 'yii\web\UrlRule',
                    'pattern' => 'crelish/<controller:[\w\-]+>/<action:[\w\-]+>',
                    'route' => 'crelish/<controller>/<action>'
                ],
                [
                    'class' => 'yii\web\UrlRule',
                    'pattern' => 'site/<action:[\w\-]+>',
                    'route' => 'site/<action>'
                ],
                [
                    'class' => 'yii\web\UrlRule',
                    'pattern' => 'data/<action:[\w\-]+>',
                    'route' => 'data/<action>'
                ],
                ['class' => 'giantbits\crelish\components\CrelishBaseUrlRule'],
                [
                    'class' => 'yii\web\UrlRule',
                    'pattern' => '<controller:[\w\-]+>/<action:[\w\-]+>',
                    'route' => '/<controller>/<action>'
                ],
                //['class' => 'yii\web\UrlRule', 'pattern' => '<lang:[\w\-]+]>/<controller:[\w\-]+>/<action:[\w\-]+>', 'route' => '/<controller>/<action>']
            ], TRUE);
        }

        // Register crelish.
        \Yii::$app->setModules([
            'crelish' => [
                'class' => 'giantbits\crelish\Module',
                'theme' => \Yii::$app->params['crelish']['theme']
            ],
            'redactor' => 'yii\redactor\RedactorModule'
        ]);
    }
}
