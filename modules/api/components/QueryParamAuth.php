<?php

namespace giantbits\crelish\modules\api\components;

use Yii;
use yii\filters\auth\QueryParamAuth as BaseQueryParamAuth;

/**
 * Enhanced QueryParamAuth that adds debugging and better error handling
 */
class QueryParamAuth extends BaseQueryParamAuth
{
    use AuthenticatesIdentity;

    /**
     * @var bool whether to enable debug logging
     */
    public $enableDebug = true;
    
    /**
     * @var bool whether to try JWT decoding for tokens
     */
    public $tryJwtDecode = true;
    
    /**
     * Identity for this request's credentials, or null (see AuthenticatesIdentity)
     */
    protected function findIdentity($user, $request, $response)
    {
        $token = $request->get($this->tokenParam);
        
        if (is_string($token) && $token !== '') {
            if ($this->enableDebug) {
                Yii::info("Token present in query param '{$this->tokenParam}'", __METHOD__);
            }
            
            // First try: Standard method - use token directly
            $identity = $user->loginByAccessToken($token, get_class($this));
            
            if ($identity !== null) {
                if ($this->enableDebug) {
                    Yii::info("User authenticated via query parameter token", __METHOD__);
                }
                return $identity;
            }
            
            // (A numeric token is no longer taken as a user id: knowing an id is not authentication)

            // Second try: Check if token is a JWT with user info
            if ($this->tryJwtDecode) {
                try {
                    if ($this->enableDebug) {
                        Yii::info("Attempting to decode token as JWT", __METHOD__);
                    }
                    
                    $secretKey = JwtSecret::requireKey(); // throws while JWT is disabled
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