<?php

namespace giantbits\crelish\modules\api\components;

use Yii;
use yii\filters\auth\QueryParamAuth as BaseQueryParamAuth;

/**
 * Enhanced QueryParamAuth that adds debugging and better error handling
 */
class QueryParamAuth extends BaseQueryParamAuth
{
    /**
     * @var bool whether to enable debug logging
     */
    public $enableDebug = true;
    
    /**
     * @var bool whether to try JWT decoding for tokens
     */
    public $tryJwtDecode = true;
    
    /**
     * @inheritdoc
     */
    public function authenticate($user, $request, $response)
    {
        $token = $request->get($this->tokenParam);
        
        if (!empty($token)) {
            if ($this->enableDebug) {
                Yii::info("Found token in query param '{$this->tokenParam}': " . substr($token, 0, 20) . "...", __METHOD__);
            }
            
            // First try: Standard method - use token directly
            $identity = $user->loginByAccessToken($token, get_class($this));
            
            if ($identity !== null) {
                if ($this->enableDebug) {
                    Yii::info("User authenticated via query parameter token", __METHOD__);
                }
                return $identity;
            }
            
            // Second try: Check if token is a numeric user ID
            if (is_numeric($token)) {
                if ($this->enableDebug) {
                    Yii::info("Token is numeric, trying to find user by ID", __METHOD__);
                }
                
                $identityClass = $user->identityClass;
                $identity = $identityClass::findIdentity($token);
                
                if ($identity !== null) {
                    if ($this->enableDebug) {
                        Yii::info("User authenticated via numeric ID token", __METHOD__);
                    }
                    return $identity;
                }
            }
            
            // Third try: Check if token is a JWT with user info
            if ($this->tryJwtDecode) {
                try {
                    if ($this->enableDebug) {
                        Yii::info("Attempting to decode token as JWT", __METHOD__);
                    }
                    
                    $secretKey = Yii::$app->params['jwtSecretKey'] ?? 'your-secret-key-here';
                    $decoded = (array)\Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secretKey, 'HS256'));
                    
                    // Try to authenticate using the 'sub' field (user ID)
                    if (isset($decoded['sub'])) {
                        if ($this->enableDebug) {
                            Yii::info("Found user ID in JWT payload, trying to authenticate", __METHOD__);
                        }
                        
                        $identityClass = $user->identityClass;
                        $identity = $identityClass::findIdentity($decoded['sub']);
                        
                        if ($identity !== null) {
                            if ($this->enableDebug) {
                                Yii::info("User authenticated via JWT payload user ID", __METHOD__);
                            }
                            return $identity;
                        }
                    }
                    
                    // Try to authenticate using the access_token in the JWT payload
                    if (isset($decoded['access_token'])) {
                        if ($this->enableDebug) {
                            Yii::info("Found access_token in JWT payload, trying to authenticate", __METHOD__);
                        }
                        
                        $identity = $user->loginByAccessToken($decoded['access_token'], get_class($this));
                        
                        if ($identity !== null) {
                            if ($this->enableDebug) {
                                Yii::info("User authenticated via JWT payload access_token", __METHOD__);
                            }
                            return $identity;
                        }
                    }
                } catch (\Exception $e) {
                    if ($this->enableDebug) {
                        Yii::info("JWT decode failed: " . $e->getMessage(), __METHOD__);
                    }
                }
            }
            
            if ($this->enableDebug) {
                Yii::warning("User not found for query parameter token", __METHOD__);
            }
        } else if ($this->enableDebug) {
            Yii::info("No token found in '{$this->tokenParam}' query parameter", __METHOD__);
        }
        
        return null;
    }
} 