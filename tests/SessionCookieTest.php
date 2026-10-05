<?php

/**
 * Crelish's session cookie defaults: HttpOnly, SameSite=Lax, Secure on HTTPS;
 * a project's own session config wins.
 *
 * Run with:  php tests/SessionCookieTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\config\ComponentsConfig;

echo "Defaults\n";
$config = ComponentsConfig::sessionConfig(['class' => \yii\web\Session::class], true);
check('class is kept', \yii\web\Session::class, $config['class']);
check('httponly', true, $config['cookieParams']['httponly']);
check('SameSite Lax', 'Lax', $config['cookieParams']['sameSite']);
check('secure on https', true, $config['cookieParams']['secure']);
$config = ComponentsConfig::sessionConfig(null, false);
check('no definition: yii\\web\\Session', \yii\web\Session::class, $config['class']);
check('plain http: secure not set (dev keeps working)', false, array_key_exists('secure', $config['cookieParams']));
check('plain http: SameSite Lax', 'Lax', $config['cookieParams']['sameSite']);
$config = ComponentsConfig::sessionConfig('yii\web\DbSession', true);
check('class string definition is kept', 'yii\web\DbSession', $config['class']);
check('class string definition gets the defaults', 'Lax', $config['cookieParams']['sameSite']);

echo "\nProject config wins\n";
$config = ComponentsConfig::sessionConfig([
    'class' => 'yii\web\DbSession',
    'name' => 'my-session',
    'cookieParams' => ['sameSite' => 'Strict', 'secure' => false, 'lifetime' => 0],
], true);
check('project class', 'yii\web\DbSession', $config['class']);
check('project name', 'my-session', $config['name']);
check('project sameSite', 'Strict', $config['cookieParams']['sameSite']);
check('project secure', false, $config['cookieParams']['secure']);
check('project lifetime', 0, $config['cookieParams']['lifetime']);
check('default httponly still added', true, $config['cookieParams']['httponly']);
$session = new \yii\web\Session();
check('an instantiated session is left alone', $session, ComponentsConfig::sessionConfig($session, true));

echo "\nApplied to a real session component\n";
shortLinkApp();
Yii::$app->set('session', ComponentsConfig::sessionConfig(['class' => \yii\web\Session::class], true));
$params = Yii::$app->session->getCookieParams();
check('session cookie params: httponly', true, $params['httponly']);
check('session cookie params: samesite', 'Lax', $params['samesite'] ?? $params['sameSite'] ?? null);
check('session cookie params: secure', true, $params['secure']);

shortLinkDone();
