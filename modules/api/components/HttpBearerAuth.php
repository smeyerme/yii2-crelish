<?php

namespace giantbits\crelish\modules\api\components;

use Yii;
use yii\filters\auth\HttpBearerAuth as BaseHttpBearerAuth;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Enhanced HttpBearerAuth that adds debugging and better error handling
 */
class HttpBearerAuth extends BaseHttpBearerAuth
{
    /**
     * @var bool whether to enable debug logging
     */
    public $enableDebug = true;
    
    /**
     * @var bool whether to try JWT decoding for Bearer tokens
     */
    public $tryJwtDecode = true;
    
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
            Yii::info("Extracted token: " . substr($token, 0, 10) . "...", __METHOD__);
        }
        
        // STEP 1: Try authenticating with the token directly
        try {
            $identity = $user->loginByAccessToken($token, get_class($this));
            
            if ($identity !== null) {
                if ($this->enableDebug) {
                    Yii::info("User authenticated via direct access token", __METHOD__);
                }
                return $identity;
            }
        } catch (\Exception $e) {
            if ($this->enableDebug) {
                Yii::warning("Direct token auth failed: " . $e->getMessage(), __METHOD__);
            }
        }
        
        // STEP 2: Try JWT token decoding
        if ($this->tryJwtDecode) {
            try {
                if ($this->enableDebug) {
                    Yii::info("Trying JWT token decoding", __METHOD__);
                }
                
                $secretKey = Yii::$app->params['jwtSecretKey'] ?? 'your-secret-key-here';
                $decoded = (array)JWT::decode($token, new Key($secretKey, 'HS256'));
                
                if ($this->enableDebug) {
                    Yii::info("JWT decoded successfully. Payload: " . json_encode($decoded), __METHOD__);
                }
                
                // Try authenticating with user ID from JWT
                if (isset($decoded['sub']) && !empty($decoded['sub'])) {
                    if ($this->enableDebug) {
                        Yii::info("Attempting to find user by ID: " . $decoded['sub'], __METHOD__);
                    }
                    
                    $identity = $user->identityClass::findIdentity($decoded['sub']);
                    
                    if ($identity !== null) {
                        if ($this->enableDebug) {
                            Yii::info("User authenticated by ID from JWT", __METHOD__);
                        }
                        return $identity;
                    } else if ($this->enableDebug) {
                        Yii::warning("User not found by ID: " . $decoded['sub'], __METHOD__);
                    }
                }
                
                // Try authenticating with access_token from JWT
                if (isset($decoded['access_token']) && !empty($decoded['access_token'])) {
                    if ($this->enableDebug) {
                        Yii::info("Attempting to find user by access_token from JWT", __METHOD__);
                    }
                    
                    $identity = $user->identityClass::findIdentityByAccessToken($decoded['access_token']);
                    
                    if ($identity !== null) {
                        if ($this->enableDebug) {
                            Yii::info("User authenticated by access_token from JWT", __METHOD__);
                        }
                        return $identity;
                    } else if ($this->enableDebug) {
                        Yii::warning("User not found by access_token from JWT", __METHOD__);
                    }
                }
                
            } catch (\Exception $e) {
                if ($this->enableDebug) {
                    Yii::warning("JWT decode error: " . $e->getMessage(), __METHOD__);
                }
            }
        }
        
        if ($this->enableDebug) {
            Yii::warning("All authentication attempts failed for token", __METHOD__);
        }
        
        return null;
    }
} 
} 
} 