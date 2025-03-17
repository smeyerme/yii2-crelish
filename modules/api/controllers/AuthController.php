<?php

namespace giantbits\crelish\modules\api\controllers;

use Yii;
use yii\web\Response;
use yii\rest\Controller;
use yii\filters\VerbFilter;
use yii\filters\Cors;
use yii\web\UnauthorizedHttpException;
use Firebase\JWT\JWT;

/**
 * Auth controller for the API
 */
class AuthController extends Controller
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
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['GET', 'POST', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => true,
                'Access-Control-Max-Age' => 86400,
            ],
        ];
        
        // Add verb filter
        $behaviors['verbs'] = [
            'class' => VerbFilter::class,
            'actions' => [
                'login' => ['post'],
                'refresh' => ['post'],
            ],
        ];
        
        return $behaviors;
    }
    
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Set response format to JSON
        Yii::$app->response->format = Response::FORMAT_JSON;
        
        // Disable CSRF validation for API
        $this->enableCsrfValidation = false;
        
        return parent::beforeAction($action);
    }
    
    /**
     * Login action to generate JWT token
     * 
     * @return array Response data
     */
    public function actionLogin(): array
    {
        // Get request body
        $request = Yii::$app->request;
        $username = $request->post('username');
        $password = $request->post('password');
        
        // Validate credentials
        if (empty($username) || empty($password)) {
            return $this->createResponse(
                null,
                false,
                'Username and password are required',
                400
            );
        }
        
        // Authenticate user
        $user = $this->authenticateUser($username, $password);
        
        if (!$user) {
            return $this->createResponse(
                null,
                false,
                'Invalid username or password',
                401
            );
        }
        
        // Generate token
        $token = $this->generateToken($user);
        
        // Return token
        return $this->createResponse([
            'token' => $token,
            'expires_at' => time() + 3600, // 1 hour expiration
        ]);
    }
    
    /**
     * Authenticate user
     * 
     * @param string $username Username
     * @param string $password Password
     * @return object|null User model or null if authentication fails
     */
    private function authenticateUser(string $username, string $password)
    {
        // Use Yii's user component for authentication
        $user = Yii::$app->user->identityClass::findByUsername($username);
        
        if ($user && $user->validatePassword($password)) {
            return $user;
        }
        
        return null;
    }
    
    /**
     * Generate JWT token
     * 
     * @param object $user User model
     * @return string JWT token
     */
    private function generateToken($user): string
    {
        $time = time();
        
        // Token payload
        $payload = [
            'iat' => $time, // Issued at
            'exp' => $time + 3600, // Expires in 1 hour
            'sub' => $user->getId(), // Subject (user ID)
            'username' => $user->username,
            'role' => $user->role ?? 'user',
        ];
        
        // Secret key - should be stored in configuration
        $key = Yii::$app->params['jwtSecretKey'] ?? 'your-secret-key-here';
        
        // Generate token
        return JWT::encode($payload, $key, 'HS256');
    }
    
    /**
     * Create standardized response
     * 
     * @param mixed $data Response data
     * @param bool $success Whether the request was successful
     * @param string $message Response message
     * @param int $code HTTP status code
     * @return array Formatted response
     */
    private function createResponse($data = null, bool $success = true, string $message = 'Success', int $code = 200): array
    {
        // Set response status code
        Yii::$app->response->statusCode = $code;
        
        // Return formatted response
        return [
            'success' => $success,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ];
    }
} 