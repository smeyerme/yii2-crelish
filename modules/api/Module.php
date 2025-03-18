<?php

namespace giantbits\crelish\modules\api;

use Yii;
use yii\filters\auth\CompositeAuth;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\auth\QueryParamAuth;
use yii\filters\Cors;
use yii\web\Response;

/**
 * API module for Crelish CMS
 */
class Module extends \yii\base\Module
{
    /**
     * @var string
     */
    public $controllerNamespace = 'giantbits\crelish\modules\api\controllers';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        
        // Configure JSON response format
        Yii::$app->response->format = Response::FORMAT_JSON;
        
        // Register module-specific translations
        $this->registerTranslations();
        
        // Set up CORS and other API-specific configurations
        $this->setupApiConfiguration();
    }
    
    /**
     * Register module translations
     */
    private function registerTranslations(): void
    {
        Yii::$app->i18n->translations['modules/crelish/api/*'] = [
            'class' => 'yii\i18n\PhpMessageSource',
            'sourceLanguage' => 'en-US',
            'basePath' => __DIR__ . '/messages',
        ];
    }
    
    /**
     * Set up API-specific configurations
     */
    private function setupApiConfiguration(): void
    {
        // Configure CORS and authentication behaviors
        Yii::$app->controllerMap['crelish-api'] = [
            'class' => 'yii\rest\Controller',
            'behaviors' => [
                'corsFilter' => [
                    'class' => Cors::class,
                    'cors' => [
                        'Origin' => ['*'],
                        'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                        'Access-Control-Request-Headers' => ['*'],
                        'Access-Control-Allow-Credentials' => true,
                        'Access-Control-Max-Age' => 86400,
                    ],
                ],
                'authenticator' => [
                    'class' => CompositeAuth::class,
                    'authMethods' => [
                        ['class' => 'giantbits\crelish\modules\api\components\JwtHttpBearerAuth'],
                        QueryParamAuth::class,
                    ],
                    'except' => ['options'],
                ],
                'contentNegotiator' => [
                    'class' => 'yii\filters\ContentNegotiator',
                    'formats' => [
                        'application/json' => Response::FORMAT_JSON,
                        'application/xml' => Response::FORMAT_XML,
                    ],
                ],
            ],
        ];
        
        // Set a default JWT secret key if not defined
        if (!isset(Yii::$app->params['jwtSecretKey'])) {
            Yii::$app->params['jwtSecretKey'] = 'your-secret-key-here';
            Yii::warning('Using default JWT secret key. Please set a secure key in your application parameters.', __METHOD__);
        }
    }
} 