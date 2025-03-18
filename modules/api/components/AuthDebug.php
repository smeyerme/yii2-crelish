<?php

namespace giantbits\crelish\modules\api\components;

use Yii;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Authentication debugging helper
 */
class AuthDebug
{
    /**
     * Debug the JWT token from the Authorization header
     * 
     * @return array Debug information
     */
    public static function debugJwt()
    {
        $headers = Yii::$app->request->headers;
        $authHeader = $headers->get('Authorization');
        
        $result = [
            'auth_header_exists' => !empty($authHeader),
            'auth_header' => $authHeader ? substr($authHeader, 0, 20) . '...' : null,
            'bearer_match' => false,
            'jwt_token' => null,
            'jwt_decode_success' => false,
            'jwt_payload' => null,
            'jwt_expired' => null,
            'user_lookup_success' => false,
            'user_lookup_method' => null,
        ];
        
        if (!$authHeader) {
            return $result;
        }
        
        // Check if Authorization header has Bearer token
        if (preg_match('/^Bearer\s+(.*?)$/', $authHeader, $matches)) {
            $result['bearer_match'] = true;
            $token = $matches[1];
            $result['jwt_token'] = substr($token, 0, 20) . '...';
            
            try {
                // Decode JWT token
                $key = Yii::$app->params['jwtSecretKey'] ?? 'your-secret-key-here';
                $decoded = (array)JWT::decode($token, new Key($key, 'HS256'));
                
                $result['jwt_decode_success'] = true;
                $result['jwt_payload'] = [
                    'iat' => $decoded['iat'] ?? null,
                    'exp' => $decoded['exp'] ?? null,
                    'sub' => $decoded['sub'] ?? null,
                    'username' => $decoded['username'] ?? null,
                    'role' => $decoded['role'] ?? null,
                    'access_token_exists' => isset($decoded['access_token']),
                    'access_token' => isset($decoded['access_token']) ? 
                        substr($decoded['access_token'], 0, 10) . '...' : null,
                ];
                
                // Check if token is expired
                if (isset($decoded['exp'])) {
                    $result['jwt_expired'] = $decoded['exp'] < time();
                }
                
                // Try to find user by access token
                if (isset($decoded['access_token'])) {
                    $identityClass = Yii::$app->user->identityClass;
                    $user = $identityClass::findIdentityByAccessToken($decoded['access_token']);
                    $result['user_lookup_success'] = $user !== null;
                    $result['user_lookup_method'] = 'access_token';
                }
                
                // If first attempt failed, try by user ID
                if (!$result['user_lookup_success'] && isset($decoded['sub'])) {
                    $identityClass = Yii::$app->user->identityClass;
                    $user = $identityClass::findIdentity($decoded['sub']);
                    $result['user_lookup_success'] = $user !== null;
                    $result['user_lookup_method'] = 'user_id';
                }
            } catch (\Exception $e) {
                $result['jwt_error'] = $e->getMessage();
            }
        }
        
        return $result;
    }
    
    /**
     * Debug the access token from the Authorization header
     * 
     * @return array Debug information
     */
    public static function debugAccessToken()
    {
        $headers = Yii::$app->request->headers;
        $authHeader = $headers->get('Authorization');
        
        $result = [
            'auth_header_exists' => !empty($authHeader),
            'auth_header' => $authHeader ? substr($authHeader, 0, 20) . '...' : null,
            'bearer_match' => false,
            'access_token' => null,
            'user_lookup_success' => false,
        ];
        
        if (!$authHeader) {
            return $result;
        }
        
        // Check if Authorization header has Bearer token
        if (preg_match('/^Bearer\s+(.*?)$/', $authHeader, $matches)) {
            $result['bearer_match'] = true;
            $token = $matches[1];
            $result['access_token'] = substr($token, 0, 20) . '...';
            
            // Try to find user by access token directly
            $identityClass = Yii::$app->user->identityClass;
            $user = $identityClass::findIdentityByAccessToken($token);
            $result['user_lookup_success'] = $user !== null;
        }
        
        return $result;
    }
    
    /**
     * Debug query parameter token
     * 
     * @param string $paramName The query parameter name, defaults to 'access_token'
     * @return array Debug information
     */
    public static function debugQueryToken($paramName = 'access_token')
    {
        $token = Yii::$app->request->get($paramName);
        
        $result = [
            'token_exists' => !empty($token),
            'token' => $token ? substr($token, 0, 20) . '...' : null,
            'user_lookup_success' => false,
        ];
        
        if (!$token) {
            return $result;
        }
        
        // Try to find user by access token directly
        $identityClass = Yii::$app->user->identityClass;
        $user = $identityClass::findIdentityByAccessToken($token);
        $result['user_lookup_success'] = $user !== null;
        
        return $result;
    }
    
    /**
     * Debug session authentication
     * 
     * @return array Debug information
     */
    public static function debugSession()
    {
        $result = [
            'session_exists' => !Yii::$app->session->isActive,
            'user_logged_in' => !Yii::$app->user->isGuest,
            'user_id' => Yii::$app->user->isGuest ? null : Yii::$app->user->id,
        ];
        
        return $result;
    }
    
    /**
     * Run all debug checks
     * 
     * @return array Combined debug results
     */
    public static function debugAll()
    {
        return [
            'jwt' => self::debugJwt(),
            'access_token' => self::debugAccessToken(),
            'query_token' => self::debugQueryToken(),
            'session' => self::debugSession(),
            'request_info' => [
                'method' => Yii::$app->request->method,
                'is_ajax' => Yii::$app->request->isAjax,
                'user_ip' => Yii::$app->request->userIP,
                'user_agent' => Yii::$app->request->userAgent,
                'cookies_enabled' => !empty(Yii::$app->request->cookies->toArray()),
                'content_type' => Yii::$app->request->contentType,
            ]
        ];
    }
} 