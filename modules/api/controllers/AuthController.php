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
                'validate-token' => ['post', 'get'],
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
        
        // Generate and store access token in the database
        $accessToken = $user->generateAccessToken();
        
        if (!$accessToken) {
            return $this->createResponse(
                null,
                false,
                'Failed to generate access token',
                500
            );
        }
        
        // Generate JWT token
        $jwtToken = $this->generateJwtToken($user, $accessToken);
        
        // Return both tokens
        return $this->createResponse([
            'access_token' => $accessToken,  // The token stored in the database (authKey)
            'jwt_token' => $jwtToken,        // The JWT token for Bearer authentication
            'expires_at' => time() + 3600,   // 1 hour expiration for JWT
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
     * @param string $accessToken The database access token
     * @return string JWT token
     */
    private function generateJwtToken($user, string $accessToken): string
    {
        $time = time();
        
        // Token payload
        $payload = [
            'iat' => $time,                          // Issued at
            'exp' => $time + 3600,                   // Expires in 1 hour
            'sub' => $user->getId(),                 // Subject (user ID)
            'username' => $user->username ?? $user->email,
            'role' => $user->role ?? 'user',
            'access_token' => $accessToken,          // Include the database token in the JWT
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
    
    /**
     * Validate token action
     * 
     * @return array Response data
     */
    public function actionValidateToken(): array
    {
        // Get request headers
        $headers = Yii::$app->request->headers;
        $authHeader = $headers->get('Authorization');
        
        // Check if Authorization header exists and has Bearer token
        if (!$authHeader || !preg_match('/^Bearer\s+(.*?)$/', $authHeader, $matches)) {
            return $this->createResponse(
                null,
                false,
                'Authorization header with Bearer token is required',
                401
            );
        }
        
        $token = $matches[1];
        
        try {
            // Decode JWT token
            $key = Yii::$app->params['jwtSecretKey'] ?? 'your-secret-key-here';
            $decoded = JWT::decode($token, $key, ['HS256']);
            
            // Verify token hasn't expired
            if ($decoded->exp < time()) {
                return $this->createResponse(
                    null,
                    false,
                    'Token has expired',
                    401
                );
            }
            
            // Verify the access token exists in the JWT payload
            if (empty($decoded->access_token)) {
                return $this->createResponse(
                    null,
                    false,
                    'Invalid token format',
                    401
                );
            }
            
            // Find user by the stored access token
            $identityClass = Yii::$app->user->identityClass;
            $user = $identityClass::findIdentityByAccessToken($decoded->access_token);
            
            if (!$user) {
                return $this->createResponse(
                    null,
                    false,
                    'Invalid token',
                    401
                );
            }
            
            // Return user information
            return $this->createResponse([
                'user_id' => $user->getId(),
                'username' => $user->username ?? $user->email,
                'role' => $user->role ?? 'user',
            ]);
            
        } catch (\Exception $e) {
            return $this->createResponse(
                null,
                false,
                'Invalid token: ' . $e->getMessage(),
                401
            );
        }
    }
} 