<?php

namespace giantbits\crelish\modules\api\components;

use Yii;
use yii\filters\auth\AuthMethod;

/**
 * SessionAuth authenticates users based on session cookies.
 *
 * This allows API access from the same browser session that a user is
 * logged into the CMS admin.
 */
class SessionAuth extends AuthMethod
{
    /**
     * @var bool whether to enable debug logging
     */
    public $enableDebug = true;
    
    /**
     * @inheritdoc
     */
    public function authenticate($user, $request, $response)
    {
        if ($this->enableDebug) {
            $this->logSessionDetails();
        }
        
        // Check if the user is already logged in
        if (!Yii::$app->user->isGuest) {
            if ($this->enableDebug) {
                Yii::info("User already logged in via session: " . Yii::$app->user->id, __METHOD__);
            }
            return Yii::$app->user->identity;
        }
        
        // If user isn't logged in, check if session ID is present
        $sessionName = Yii::$app->session->getName();
        $cookies = $request->cookies;
        
        if ($cookies->has($sessionName)) {
            if ($this->enableDebug) {
                Yii::info("Found session cookie: " . $sessionName, __METHOD__);
            }
            
            // Try to restore the session
            if (!Yii::$app->session->isActive) {
                Yii::$app->session->open();
                
                if ($this->enableDebug) {
                    Yii::info("Opened session", __METHOD__);
                }
            }
            
            // Check if user is logged in after session restoration
            if (!Yii::$app->user->isGuest) {
                if ($this->enableDebug) {
                    Yii::info("User authenticated via restored session: " . Yii::$app->user->id, __METHOD__);
                }
                return Yii::$app->user->identity;
            } else if ($this->enableDebug) {
                Yii::info("Session found but user still not authenticated", __METHOD__);
            }
        } else if ($this->enableDebug) {
            Yii::info("No session cookie found", __METHOD__);
        }
        
        return null;
    }
    
    /**
     * Log details about the current session and cookies for debugging
     */
    private function logSessionDetails()
    {
        $sessionName = Yii::$app->session->getName();
        $cookies = Yii::$app->request->cookies;
        $hasSessionCookie = $cookies->has($sessionName);
        $sessionCookieValue = $hasSessionCookie ? substr($cookies->getValue($sessionName), 0, 10) . '...' : 'none';
        
        $details = [
            'session_active' => Yii::$app->session->isActive,
            'session_name' => $sessionName,
            'has_session_cookie' => $hasSessionCookie,
            'session_cookie_value' => $sessionCookieValue,
            'user_is_guest' => Yii::$app->user->isGuest,
            'user_id' => Yii::$app->user->isGuest ? null : Yii::$app->user->id,
            'available_cookies' => array_keys($cookies->toArray()),
            'headers' => array_keys(Yii::$app->request->headers->toArray()),
            'accept_header' => Yii::$app->request->headers->get('Accept'),
            'user_agent' => Yii::$app->request->userAgent,
            'is_ajax' => Yii::$app->request->isAjax,
        ];
        
        Yii::info("Session authentication details: " . json_encode($details, JSON_PRETTY_PRINT), __METHOD__);
    }
} 