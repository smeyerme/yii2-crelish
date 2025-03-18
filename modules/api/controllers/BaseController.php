<?php

namespace giantbits\crelish\modules\api\controllers;

use Yii;
use yii\filters\auth\CompositeAuth;
use yii\filters\Cors;
use yii\rest\Controller;
use yii\web\Response;
use giantbits\crelish\modules\api\components\JwtHttpBearerAuth;
use giantbits\crelish\modules\api\components\SessionAuth;
use giantbits\crelish\modules\api\components\HttpBearerAuth;
use giantbits\crelish\modules\api\components\QueryParamAuth;

/**
 * Base API controller for Crelish CMS
 */
class BaseController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        
        // Add CORS filter
        $behaviors['corsFilter'] = [
            'class' => Cors::class,
            'cors' => [
                // Allow requests from any origin for development
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => true,
                'Access-Control-Max-Age' => 86400,
            ],
        ];
        
        // Add authentication methods
        $behaviors['authenticator'] = [
            'class' => CompositeAuth::class,
            'authMethods' => [
                // Session auth for browser requests
                [
                    'class' => SessionAuth::class,
                    'enableDebug' => true,
                ],
                // JWT auth for API clients
                [
                    'class' => JwtHttpBearerAuth::class,
                    'header' => 'Authorization',
                    'pattern' => '/^Bearer\s+(.*?)$/',
                    'enableDebug' => true,
                    'allowDirectJwtAuth' => true,
                ],
                // HTTP Bearer auth (for access_token)
                [
                    'class' => HttpBearerAuth::class,
                    'enableDebug' => true,
                    'tryJwtDecode' => true,
                ],
                // Query param auth (for token in URL)
                [
                    'class' => QueryParamAuth::class,
                    'tokenParam' => 'access_token',
                    'enableDebug' => true,
                    'tryJwtDecode' => true,
                ],
                // Basic auth as fallback
                'yii\filters\auth\HttpBasicAuth',
            ],
            'except' => ['options'], // Only exclude OPTIONS requests
            'optional' => ['index', 'view'], // Make read operations optional for authentication during development
        ];
        
        // Add content negotiation
        $behaviors['contentNegotiator'] = [
            'class' => 'yii\filters\ContentNegotiator',
            'formats' => [
                'application/json' => Response::FORMAT_JSON,
                'application/xml' => Response::FORMAT_XML,
            ],
        ];
        
        // Add rate limiting
        $behaviors['rateLimiter'] = [
            'class' => 'yii\filters\RateLimiter',
        ];
        
        return $behaviors;
    }
    
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        
        // Set response format to JSON
        Yii::$app->response->format = Response::FORMAT_JSON;
        
        return true;
    }
    
    /**
     * Handle OPTIONS requests for CORS preflight
     */
    public function actionOptions(): array
    {
        if (Yii::$app->getRequest()->getMethod() !== 'OPTIONS') {
            Yii::$app->getResponse()->setStatusCode(405);
            return ['error' => 'Method not allowed'];
        }
        
        Yii::$app->getResponse()->getHeaders()->set('Allow', 'GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS');
        return [];
    }
    
    /**
     * Create standardized API response
     * 
     * @param mixed $data The data to return
     * @param bool $success Whether the request was successful
     * @param string|null $message Optional message
     * @param int $statusCode HTTP status code
     * @return array Standardized response array
     */
    protected function createResponse($data = null, bool $success = true, ?string $message = null, int $statusCode = 200): array
    {
        Yii::$app->response->statusCode = $statusCode;
        
        $response = [
            'success' => $success,
            'code' => $statusCode,
        ];
        
        if ($message !== null) {
            $response['message'] = $message;
        }
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        return $response;
    }
} 