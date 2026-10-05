<?php

/**
 * Admin access control: the CrelishBaseController guard, public exemptions,
 * admin controllers outside CrelishBaseController, translation/docs path
 * checks and the API module.
 *
 * Run with:  php tests/AccessControlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CrelishAccess;
use giantbits\crelish\components\CrelishBaseController;

/**
 * Logged-in user stub with a role
 */
class AccessTestIdentity implements \yii\web\IdentityInterface
{
    public string $uuid = 'a0000000-0000-4000-8000-000000000001';

    public function __construct(public mixed $role)
    {
    }

    public static function findIdentity($id)
    {
        return null;
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return null;
    }

    public function getId()
    {
        return $this->uuid;
    }

    public function getAuthKey()
    {
        return null;
    }

    public function validateAuthKey($authKey)
    {
        return false;
    }
}

class AccessTestAssetManager extends \yii\web\AssetManager
{
    public function getBundle($name, $publish = true)
    {
        return new \yii\web\AssetBundle();
    }
}

$root = sys_get_temp_dir() . '/crelish-access-test-' . getmypid();
@mkdir($root . '/workspace/elements', 0777, true);
@mkdir($root . '/web', 0777, true);
@mkdir($root . '/runtime', 0777, true);
@mkdir($root . '/messages/de', 0777, true);
file_put_contents($root . '/workspace/elements/user.json', '{"key":"user","fields":[]}');
file_put_contents($root . '/messages/de/app.php', "<?php\nreturn ['Hello' => 'Hallo'];\n");
register_shutdown_function(static function () use ($root): void {
    \yii\helpers\FileHelper::removeDirectory($root);
});

/**
 * Fresh app, optionally logged in with a role (null = guest).
 */
function accessApp(mixed $role = null, array $server = [], array $params = []): \yii\web\Application
{
    global $root;

    // shortLinkApp() merges into $_SERVER; drop headers of earlier scenarios
    unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_AUTHORIZATION']);
    $_POST = [];

    $app = shortLinkApp($params, $server, [
        'basePath' => $root,
        'vendorPath' => dirname(__DIR__) . '/vendor',
        'components' => [
            // as configured by config/ComponentsConfig.php
            'user' => ['loginUrl' => ['crelish/user/login']],
            // controllers register asset bundles in init(); nothing to publish here
            'assetManager' => ['class' => AccessTestAssetManager::class],
        ],
    ]);
    Yii::setAlias('@webroot', $root . '/web');
    Yii::setAlias('@workspace', $root . '/workspace');

    if ($role !== null) {
        $app->user->setIdentity(new AccessTestIdentity($role));
    }

    return $app;
}

/**
 * Run the controller's beforeAction for one action.
 *
 * @return array{0: bool|string, 1: int, 2: ?string} result (or exception class), status code, Location header
 */
function runGuard(string $class, string $id, string $actionId): array
{
    try {
        $controller = new $class($id, Yii::$app);
        $action = $controller->createAction($actionId);

        if ($action === null) {
            return ['no such action', 0, null];
        }

        $controller->action = $action;
        $result = $controller->beforeAction($action);
    } catch (\Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    return [$result, Yii::$app->response->statusCode, Yii::$app->response->headers->get('Location')];
}

$representative = [
    'Page update' => [\giantbits\crelish\controllers\PageController::class, 'page', 'update'],
    'Translation save' => [\giantbits\crelish\controllers\TranslationController::class, 'translation', 'save'],
    'Asset api-delete' => [\giantbits\crelish\controllers\AssetController::class, 'asset', 'api-delete'],
    'Asset api-upload' => [\giantbits\crelish\controllers\AssetController::class, 'asset', 'api-upload'],
    'Analytics export' => [\giantbits\crelish\controllers\AnalyticsController::class, 'analytics', 'export'],
    'Content api-get' => [\giantbits\crelish\controllers\ContentController::class, 'content', 'api-get'],
    'Newsletter publish' => [\giantbits\crelish\controllers\NewsletterController::class, 'newsletter', 'publish'],
];

echo "Guests are stopped\n";
foreach ($representative as $name => [$class, $id, $actionId]) {
    accessApp();
    [$result, $status, $location] = runGuard($class, $id, $actionId);
    check("guest: $name does not run", false, $result);
    check("guest: $name redirects to the login form", true, $status === 302 && str_contains((string)$location, 'crelish/user/login'));
}

echo "\nGuest AJAX/JSON requests get a 403\n";
accessApp(null, ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
[$result, $status, $location] = runGuard(\giantbits\crelish\controllers\AssetController::class, 'asset', 'api-delete');
check('ajax guest: asset api-delete does not run', false, $result);
check('ajax guest: 403', 403, $status);
check('ajax guest: no redirect', null, $location);
accessApp(null, ['HTTP_ACCEPT' => 'application/json']);
[$result, $status] = runGuard(\giantbits\crelish\controllers\ContentController::class, 'content', 'api-get');
check('json guest: content api-get does not run', false, $result);
check('json guest: 403', 403, $status);

echo "\nUsers without the admin role are stopped\n";
foreach ($representative as $name => [$class, $id, $actionId]) {
    accessApp(1);
    [$result, $status, $location] = runGuard($class, $id, $actionId);
    check("role 1: $name does not run", false, $result);
    check("role 1: $name redirects home", true, $status === 302 && !str_contains((string)$location, 'login'));
}
accessApp(8, ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
[$result, $status] = runGuard(\giantbits\crelish\controllers\AssetController::class, 'asset', 'api-upload');
check('ajax role 8: asset api-upload does not run', false, $result);
check('ajax role 8: 403', 403, $status);

echo "\nAdmins pass\n";
foreach ($representative as $name => [$class, $id, $actionId]) {
    accessApp(9);
    [$result, $status] = runGuard($class, $id, $actionId);
    check("admin: $name runs", true, $result);
    check("admin: $name is not redirected", 200, $status);
}
accessApp('9');
check('admin role as string passes', true, CrelishAccess::isAdmin());

echo "\nPublic routes stay public\n";
$public = [
    'asset glide' => [\giantbits\crelish\controllers\AssetController::class, 'asset', 'glide'],
    'asset download' => [\giantbits\crelish\controllers\AssetController::class, 'asset', 'download'],
    'user login' => [\giantbits\crelish\controllers\UserController::class, 'user', 'login'],
    'user logout' => [\giantbits\crelish\controllers\UserController::class, 'user', 'logout'],
    'track click' => [\giantbits\crelish\controllers\TrackController::class, 'track', 'click'],
];
foreach ($public as $name => [$class, $id, $actionId]) {
    accessApp();
    [$result, $status] = runGuard($class, $id, $actionId);
    check("guest: $name runs", true, $result);
    check("guest: $name is not redirected", 200, $status);
}
accessApp(1);
[$result] = runGuard(\giantbits\crelish\controllers\UserController::class, 'user', 'logout');
check('role 1: user logout runs', true, $result);
check('public routes are listed in one place', ['user/login', 'user/logout', 'asset/glide', 'asset/download', 'track/click'], array_keys(CrelishAccess::PUBLIC_ROUTES));

echo "\nEvery admin controller is guarded\n";
foreach (glob(dirname(__DIR__) . '/controllers/*Controller.php') as $file) {
    $class = 'giantbits\\crelish\\controllers\\' . basename($file, '.php');
    if (!class_exists($class) || !is_subclass_of($class, CrelishBaseController::class)) {
        continue;
    }
    $id = \yii\helpers\Inflector::camel2id(substr(basename($file, '.php'), 0, -strlen('Controller')));
    $actions = [];
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (preg_match('/^action([A-Z]\w*)$/', $method->name, $m) && $method->name !== 'actionError') {
            $actions[] = \yii\helpers\Inflector::camel2id($m[1]);
        }
    }
    foreach ($actions as $actionId) {
        if (CrelishAccess::isPublicRoute("$id/$actionId")) {
            continue;
        }
        accessApp();
        [$result] = runGuard($class, $id, $actionId);
        check("guest: $id/$actionId does not run", false, $result);
    }
}

shortLinkDone();
