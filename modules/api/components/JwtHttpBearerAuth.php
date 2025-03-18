<?php

namespace giantbits\crelish\modules\api\components;

use Yii;
use yii\filters\auth\AuthMethod;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

/**
 * Enhanced JWT HTTP Bearer authentication for API requests
 */
class JwtHttpBearerAuth extends AuthMethod
{
    /**
     * @var string the HTTP header name
     */
    public $header = 'Authorization';

    /**
     * @var string a pattern to use to extract the HTTP authentication value
     */
    public $pattern = '/^Bearer\s+(.*?)$/';
    
    /**
     * @var bool whether to enable debug logging
     */
    public $enableDebug = true;
    
    /**
     * @var bool whether to allow direct JWT authentication without requiring access_token
     */
    public $allowDirectJwtAuth = true;

    /**
     * @inheritdoc
     */
    public function authenticate($user, $request, $response)
    {
        $authHeader = $request->getHeaders()->get($this->header);
        
        if ($authHeader === null) {
            if ($this->enableDebug) {
                Yii::info("No {$this->header} header found", __METHOD__);
            }
            return null;
        }
        
        if ($this->enableDebug) {
            Yii::info("Processing {$this->header} header: " . substr($authHeader, 0, 20) . "...", __METHOD__);
        }
        
        if (!preg_match($this->pattern, $authHeader, $matches)) {
            if ($this->enableDebug) {
                Yii::warning("Authorization header doesn't match Bearer pattern", __METHOD__);
            }
            return null;
        }
        
        $token = $matches[1];
        
        if ($this->enableDebug) {
            Yii::info("Attempting to decode JWT token", __METHOD__);
        }
        
        try {
            // Decode JWT token using updated method signature with Key object
            $secretKey = Yii::$app->params['jwtSecretKey'] ?? 'your-secret-key-here';
            $decoded = (array)JWT::decode($token, new Key($secretKey, 'HS256'));
            
            if ($this->enableDebug) {
                Yii::info("Successfully decoded JWT token", __METHOD__);
            }
            
            // Check if token has expired
            if (isset($decoded['exp']) && $decoded['exp'] < time()) {
                if ($this->enableDebug) {
                    Yii::warning("JWT token has expired", __METHOD__);
                }
                return null;
            }
            
            // First try: Authenticate using access_token from JWT payload
            if (isset($decoded['access_token'])) {
                if ($this->enableDebug) {
                    Yii::info("Authenticating with access_token from JWT payload", __METHOD__);
                }
                
                $identity = $user->identityClass::findIdentityByAccessToken($decoded['access_token']);
                
                if ($identity !== null) {
                    if ($this->enableDebug) {
                        Yii::info("User authenticated via access_token in JWT payload", __METHOD__);
                    }
                    return $identity;
                }
                
                if ($this->enableDebug) {
                    Yii::warning("Failed to authenticate with access_token from JWT payload", __METHOD__);
                }
            }
            
            // Second try: Authenticate using user ID from JWT payload (direct JWT auth)
            if ($this->allowDirectJwtAuth && isset($decoded['sub'])) {
                if ($this->enableDebug) {
                    Yii::info("Authenticating with user ID (sub) from JWT payload", __METHOD__);
                }
                
                $identity = $user->identityClass::findIdentity($decoded['sub']);
                
                if ($identity !== null) {
                    if ($this->enableDebug) {
                        Yii::info("User authenticated via user ID in JWT payload", __METHOD__);
                    }
                    return $identity;
                }
                
                if ($this->enableDebug) {
                    Yii::warning("Failed to authenticate with user ID from JWT payload", __METHOD__);
                }
            }
            
            // Third try: Use the JWT token directly as an access token
            if ($this->enableDebug) {
                Yii::info("Attempting to authenticate using JWT token as access token", __METHOD__);
            }
            
            $identity = $user->identityClass::findIdentityByAccessToken($token);
            
            if ($identity !== null) {
                if ($this->enableDebug) {
                    Yii::info("User authenticated via JWT token as access token", __METHOD__);
                }
                return $identity;
            }
            
            if ($this->enableDebug) {
                Yii::warning("All authentication methods failed for JWT token", __METHOD__);
            }
            
        } catch (ExpiredException $e) {
            if ($this->enableDebug) {
                Yii::warning("JWT token expired: " . $e->getMessage(), __METHOD__);
            }
        } catch (\Exception $e) {
            if ($this->enableDebug) {
                Yii::warning("JWT token decode failed: " . $e->getMessage(), __METHOD__);
            }
            
            // Try using the token directly as an access token
            $identity = $user->identityClass::findIdentityByAccessToken($token);
            
            if ($identity !== null) {
                if ($this->enableDebug) {
                    Yii::info("User authenticated via raw token as access token", __METHOD__);
                }
                return $identity;
            }
        }
        
        return null;
    }

    /**
     * @inheritdoc
     */
    public function challenge($response)
    {
        $response->getHeaders()->set('WWW-Authenticate', 'Bearer realm="api"');
    }
} 