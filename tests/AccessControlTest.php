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

echo "\nAsset API is not open to guests\n";
accessApp();
$guestActions = [];
foreach ((new \giantbits\crelish\controllers\AssetController('asset', Yii::$app))->behaviors()['access']['rules'] as $rule) {
    if (in_array('?', $rule['roles'] ?? [], true)) {
        $guestActions = array_merge($guestActions, $rule['actions'] ?? ['*']);
    }
}
sort($guestActions);
check('asset: only glide and download allow guests', ['download', 'glide'], $guestActions);

echo "\nTranslation save only writes existing message files of allowed languages\n";
/**
 * @return string 'ok' or the exception class
 */
function saveTranslations(string $language, array $translations): string
{
    accessApp(9, ['REQUEST_METHOD' => 'POST'], ['languages' => ['de', 'fr']]);
    Yii::$app->request->setBodyParams(['Translations' => $translations]);
    $controller = new \giantbits\crelish\controllers\TranslationController('translation', Yii::$app);

    try {
        $controller->actionSave($language);
        return 'ok';
    } catch (\Throwable $e) {
        return get_class($e);
    }
}

$bad = \yii\web\BadRequestHttpException::class;
check('valid language and category is saved', 'ok', saveTranslations('de', ['app' => ['Hello' => 'Servus']]));
check('saved file has the new value', ['Hello' => 'Servus'], include $root . '/messages/de/app.php');
check('language traversal is rejected', $bad, saveTranslations('../../config', ['app' => ['x' => 'y']]));
check('language traversal writes nothing', false, file_exists($root . '/config/app.php'));
check('language outside the configured languages is rejected', $bad, saveTranslations('xx', ['app' => ['x' => 'y']]));
check('category traversal is rejected', $bad, saveTranslations('de', ['../../web/shell' => ['x' => '<?php echo 1;']]));
check('category traversal writes nothing', false, file_exists($root . '/web/shell.php'));
check('category with a dot is rejected', $bad, saveTranslations('de', ['app.php' => ['x' => 'y']]));
check('unknown category is rejected', $bad, saveTranslations('de', ['newcategory' => ['x' => 'y']]));
check('unknown category writes nothing', false, file_exists($root . '/messages/de/newcategory.php'));
check('allowed language without message files is rejected', $bad, saveTranslations('fr', ['app' => ['x' => 'y']]));
check('non-array translations are rejected', $bad, saveTranslations('de', ['app' => 'oops']));
check('nothing was changed by the rejected requests', ['Hello' => 'Servus'], include $root . '/messages/de/app.php');

accessApp(9, [], ['languages' => ['de']]);
$controller = new \giantbits\crelish\controllers\TranslationController('translation', Yii::$app);
try {
    $controller->actionIndex('../../config');
    $result = 'ok';
} catch (\Throwable $e) {
    $result = get_class($e);
}
check('translation index rejects a traversal language', $bad, $result);
$rules = $controller->behaviors()['access']['rules'] ?? [];
check('translation controller has an AccessControl rule for logged-in users', true, ($controller->behaviors()['access']['class'] ?? null) === \yii\filters\AccessControl::class && in_array(['allow' => true, 'roles' => ['@']], $rules, true));

echo "\nAdmin controllers outside CrelishBaseController\n";
$target = [\giantbits\crelish\controllers\ContentTargetController::class, 'content-target', 'search'];
accessApp();
[$result] = runGuard(...$target);
check('content-target: guest is stopped', true, $result !== true);
accessApp(1);
[$result] = runGuard(...$target);
check('content-target: role 1 is forbidden', \yii\web\ForbiddenHttpException::class, strtok((string)$result, ':'));
accessApp(9);
[$result] = runGuard(...$target);
check('content-target: admin passes', true, $result);
check('adminRule requires login', ['@'], CrelishAccess::adminRule()['roles']);

echo "\nDocumentation pages stay inside the docs directory\n";
accessApp(9);
$docs = new \giantbits\crelish\controllers\DocumentationController('documentation', Yii::$app);
$docsDir = dirname(__DIR__) . '/docs';
check('docs: a page resolves to its file', realpath($docsDir . '/getting-started.md'), $docs->docFile('getting-started'));
check('docs: a page in a subdirectory resolves', true, is_string($docs->docFile('superpowers/plans/2026-09-30-menus-followups')));
foreach (['../CLAUDE', '../../../../etc/passwd', 'superpowers/../../CLAUDE', '/etc/passwd', '..', 'getting-started.md', "getting-started\0", 'missing-page', ''] as $page) {
    check('docs: ' . json_encode($page) . ' is not served', null, $docs->docFile($page));
}
try {
    $docs->actionRead('../CLAUDE');
    $result = 'served';
} catch (\Throwable $e) {
    $result = get_class($e);
}
check('docs: read of a traversal page is a 404', \yii\web\NotFoundHttpException::class, $result);
$link = $docsDir . '/access-test-link-' . getmypid() . '.md';
@symlink(dirname(__DIR__) . '/CLAUDE.md', $link);
check('docs: a symlink pointing outside is not served', null, $docs->docFile(basename($link, '.md')));
@unlink($link);

echo "\nAPI module\n";
const API_ADMIN = 'a0000000-0000-4000-8000-0000000000a9';
const STRONG_SECRET = 'a-long-random-jwt-secret-for-the-tests-0123456789';
const ADMIN_PASSWORD = 'correct horse battery staple';
define('ADMIN_PASSWORD_HASH', password_hash(ADMIN_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));

/**
 * Fresh app with a user table, CrelishUser as identity class and the API module.
 */
function apiApp(mixed $role = null, array $server = [], array $appParams = []): \giantbits\crelish\modules\api\Module
{
    $app = accessApp($role, $server);
    $app->params = array_merge($app->params, $appParams);
    $app->user->identityClass = \giantbits\crelish\components\CrelishUser::class;
    $app->db->createCommand()->createTable('user', [
        'uuid' => 'varchar(36) NOT NULL PRIMARY KEY',
        'email' => 'varchar(255) NULL',
        'authKey' => 'varchar(255) NULL',
        'role' => 'integer NULL',
        'state' => 'integer NULL',
        'password' => 'varchar(255) NULL',
        'nameFirst' => 'varchar(255) NULL',
        'nameLast' => 'varchar(255) NULL',
    ])->execute();
    $app->db->createCommand()->insert('user', ['uuid' => API_ADMIN, 'email' => 'admin@example.test', 'authKey' => 'admin-auth-key-0123456789', 'role' => 9, 'state' => 2, 'password' => ADMIN_PASSWORD_HASH, 'nameFirst' => 'Ada', 'nameLast' => 'Admin'])->execute();
    $app->db->createCommand()->insert('user', ['uuid' => 'a0000000-0000-4000-8000-0000000000e0', 'email' => 'empty@example.test', 'authKey' => '', 'role' => 9, 'state' => 2])->execute();

    $module = new \giantbits\crelish\modules\api\Module('crelish-api', $app);
    $app->setModule('crelish-api', $module);

    return $module;
}

class AccessTestUserRecord extends \yii\db\ActiveRecord
{
    /** set by CrelishUser::crelishLogin() */
    public $initials;

    public static function tableName(): string
    {
        return 'user';
    }
}
class_alias(AccessTestUserRecord::class, 'app\\workspace\\models\\User');

/**
 * Run the API content controller's beforeAction with these query params.
 */
function apiGuard(\giantbits\crelish\modules\api\Module $module, string $actionId, array $query): bool|string
{
    Yii::$app->request->setQueryParams($query);

    try {
        $controller = new \giantbits\crelish\modules\api\controllers\ContentController('content', $module);
        $action = $controller->createAction($actionId);
        $controller->action = $action;
        return $controller->beforeAction($action);
    } catch (\Throwable $e) {
        return get_class($e);
    }
}

function jwtFor(string $sub, string $key): string
{
    return \Firebase\JWT\JWT::encode(['iat' => time(), 'exp' => time() + 3600, 'sub' => $sub], $key, 'HS256');
}

$unauthorized = \yii\web\UnauthorizedHttpException::class;
$forbidden = \yii\web\ForbiddenHttpException::class;
check('api guest: index is unauthorized', $unauthorized, apiGuard(apiApp(), 'index', ['type' => 'page']));
check('api guest: view is unauthorized', $unauthorized, apiGuard(apiApp(), 'view', ['type' => 'page', 'id' => 'x']));
check('api guest: user list is unauthorized', $unauthorized, apiGuard(apiApp(), 'index', ['type' => 'user']));
check('api session admin: index passes', true, apiGuard(apiApp(9), 'index', ['type' => 'page']));
check('api session admin: user list passes', true, apiGuard(apiApp(9), 'index', ['type' => 'user']));
check('api session admin: create passes', true, apiGuard(apiApp(9), 'create', ['type' => 'page']));
check('api role 1: page list passes', true, apiGuard(apiApp(1), 'index', ['type' => 'page']));
check('api role 1: user list is forbidden', $forbidden, apiGuard(apiApp(1), 'index', ['type' => 'user']));
check('api role 1: user record is forbidden', $forbidden, apiGuard(apiApp(1), 'view', ['type' => 'user', 'id' => API_ADMIN]));
check('api role 1: create is forbidden', $forbidden, apiGuard(apiApp(1), 'create', ['type' => 'page']));
check('api role 1: delete is forbidden', $forbidden, apiGuard(apiApp(1), 'delete', ['type' => 'page', 'id' => 'x']));

echo "\nAPI tokens\n";
check('numeric access_token is no login', $unauthorized, apiGuard(apiApp(null, ['QUERY_STRING' => '']), 'index', ['type' => 'page', 'access_token' => '1']));
check('empty bearer token is no login', $unauthorized, apiGuard(apiApp(null, ['HTTP_AUTHORIZATION' => 'Bearer ']), 'index', ['type' => 'page']));
check('auth key as access_token still logs in', true, apiGuard(apiApp(), 'index', ['type' => 'page', 'access_token' => 'admin-auth-key-0123456789']));
apiApp();
check('findIdentity of an unknown user is null', null, \giantbits\crelish\components\CrelishUser::findIdentity('no-such-user'));
check('findIdentityByAccessToken of an empty token is null', null, \giantbits\crelish\components\CrelishUser::findIdentityByAccessToken(''));

use giantbits\crelish\modules\api\components\JwtSecret;

foreach (['missing' => [], 'the built-in default' => ['jwtSecretKey' => 'your-secret-key-here'], 'the config/params.php default' => ['jwtSecretKey' => 'your-secret-key-change-this-in-production'], 'a short secret' => ['jwtSecretKey' => 'short-secret'], 'a non-string' => ['jwtSecretKey' => 12345678901234567890123456789012345]] as $name => $appParams) {
    apiApp(null, [], $appParams);
    check("jwt secret $name: JWT is disabled", false, JwtSecret::isEnabled());
    check("jwt secret $name: no default is put into params", $appParams['jwtSecretKey'] ?? null, Yii::$app->params['jwtSecretKey'] ?? null);
}
$forgedDefault = jwtFor(API_ADMIN, 'your-secret-key-here');
check('jwt forged with the default key: bearer is unauthorized', $unauthorized, apiGuard(apiApp(null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $forgedDefault]), 'index', ['type' => 'page']));
check('jwt forged with the default key: query param is unauthorized', $unauthorized, apiGuard(apiApp(), 'index', ['type' => 'page', 'access_token' => $forgedDefault]));
$short = jwtFor(API_ADMIN, str_repeat('k', 31));
check('jwt with a 31-char secret: bearer is unauthorized', $unauthorized, apiGuard(apiApp(null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $short], ['jwtSecretKey' => str_repeat('k', 31)]), 'index', ['type' => 'page']));
apiApp(null, [], ['jwtSecretKey' => STRONG_SECRET]);
check('jwt with a strong secret is enabled', true, JwtSecret::isEnabled());
check('jwt with a strong secret: valid token logs in', true, apiGuard(apiApp(null, ['HTTP_AUTHORIZATION' => 'Bearer ' . jwtFor(API_ADMIN, STRONG_SECRET)], ['jwtSecretKey' => STRONG_SECRET]), 'index', ['type' => 'page']));
check('jwt with a strong secret: token signed with another key is unauthorized', $unauthorized, apiGuard(apiApp(null, ['HTTP_AUTHORIZATION' => 'Bearer ' . $forgedDefault], ['jwtSecretKey' => STRONG_SECRET]), 'index', ['type' => 'page']));
check('jwt with a strong secret: unknown sub is unauthorized', $unauthorized, apiGuard(apiApp(null, ['HTTP_AUTHORIZATION' => 'Bearer ' . jwtFor('nobody', STRONG_SECRET)], ['jwtSecretKey' => STRONG_SECRET]), 'index', ['type' => 'page']));

echo "\nAdmin login form\n";
/**
 * crelishLogin() with this data on a fresh app; returns whether a user is logged in afterwards and who.
 */
function loginWith(array $data): array
{
    apiApp();
    $result = \giantbits\crelish\components\CrelishUser::crelishLogin($data);

    return [(bool)$result, Yii::$app->user->isGuest ? null : Yii::$app->user->id];
}

check('login: uuid alone does not log in', [false, null], loginWith(['uuid' => API_ADMIN]));
check('login: uuid with a wrong password does not log in', [false, null], loginWith(['uuid' => API_ADMIN, 'password' => 'wrong']));
check('login: uuid with email and a wrong password does not log in', [false, null], loginWith(['uuid' => API_ADMIN, 'email' => 'admin@example.test', 'password' => 'wrong']));
check('login: email with a wrong password does not log in', [false, null], loginWith(['email' => 'admin@example.test', 'password' => 'wrong']));
check('login: email and password log in', [true, API_ADMIN], loginWith(['email' => 'admin@example.test', 'password' => ADMIN_PASSWORD]));
check('login: a uuid of another user next to correct credentials is ignored', [true, API_ADMIN], loginWith(['uuid' => 'a0000000-0000-4000-8000-0000000000e0', 'email' => 'admin@example.test', 'password' => ADMIN_PASSWORD]));
check('login: missing fields do not log in', [false, null], loginWith([]));
check('login: array values do not log in', [false, null], loginWith(['email' => ['admin@example.test'], 'password' => [ADMIN_PASSWORD]]));
apiApp();
check('login: null data does not log in', false, (bool)\giantbits\crelish\components\CrelishUser::crelishLogin(null));
check('login: then the admin passes the guard', true, (function (): bool {
    apiApp();
    \giantbits\crelish\components\CrelishUser::crelishLogin(['email' => 'admin@example.test', 'password' => ADMIN_PASSWORD]);
    return CrelishAccess::isAdmin();
})());

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
