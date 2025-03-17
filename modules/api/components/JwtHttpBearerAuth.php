<?php

namespace giantbits\crelish\modules\api\components;

use Yii;
use yii\filters\auth\AuthMethod;
use yii\web\UnauthorizedHttpException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

/**
 * JwtHttpBearerAuth is an action filter that supports the authentication based on JWT tokens.
 */
class JwtHttpBearerAuth extends AuthMethod
{
    /**
     * @var string the HTTP authentication realm
     */
    public $realm = 'api';

    /**
     * @var string a pattern to use to extract the HTTP authentication value
     */
    public $pattern = '/^Bearer\s+(.*?)$/';

    /**
     * @inheritdoc
     */
    public function authenticate($user, $request, $response)
    {
        $authHeader = $request->getHeaders()->get('Authorization');
        
        if ($authHeader !== null) {
            if (preg_match($this->pattern, $authHeader, $matches)) {
                $jwtToken = $matches[1];
                
                try {
                    // Decode JWT token
                    $key = Yii::$app->params['jwtSecretKey'] ?? 'your-secret-key-here';
                    $decoded = JWT::decode($jwtToken, new Key($key, 'HS256'));
                    
                    // Verify token hasn't expired
                    if ($decoded->exp < time()) {
                        throw new ExpiredException('Token has expired');
                    }
                    
                    // Verify the access token exists in the JWT payload
                    if (empty($decoded->access_token)) {
                        throw new \Exception('Invalid token format');
                    }
                    
                    // Find user by the stored access token
                    $identity = $user->identityClass::findIdentityByAccessToken($decoded->access_token);
                    
                    if ($identity) {
                        return $identity;
                    }
                } catch (\Exception $e) {
                    Yii::warning('JWT authentication failed: ' . $e->getMessage(), __METHOD__);
                    $this->handleFailure($response);
                }
            }
        }
        
        return null;
    }

    /**
     * @inheritdoc
     */
    public function challenge($response)
    {
        $response->getHeaders()->set('WWW-Authenticate', "Bearer realm=\"{$this->realm}\"");
    }
} 