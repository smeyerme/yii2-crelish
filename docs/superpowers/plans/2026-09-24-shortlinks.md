# Short Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A built-in crelish feature for campaign/print short links (`/go/<code>`) with redirect tracking through the existing analytics tables and print-quality QR export.

**Architecture:** An ActiveRecord `ShortLink` with its own crelish migration. A URL rule placed before `CrelishBaseUrlRule` routes hits to a lightweight public redirect controller. `ShortLinkResolver` decides the destination at redirect time. A new `CrelishAnalyticsComponent::trackEvent()` records hits as element events. An admin controller (list, edit, QR, statistics) follows the Newsletter/Translation pattern. QR codes are encoded with bacon/bacon-qr-code and drawn by our own renderer at exact physical sizes.

**Tech Stack:** PHP 8.2+, Yii 2.0.53, Twig 3 (yii2-twig), bacon/bacon-qr-code ^3, setasign/fpdf ^1.8, GD, ZipArchive, Chart.js 3.7.1 (already used by analytics views), SQLite in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-09-24-shortlinks-design.md` (read it before starting; section 14 lists decisions made while planning).

**Repositories:**
- crelish: `/Users/smyr/Sites/gbits/giantbits/yii2-crelish`, branch `feature/shortlinks` (already exists; spec commit `50dff49`). Tasks 1–8, 10–12 happen here.
- forum-holzbau: `/Users/smyr/Sites/gbits/crelish.forum-holzbau`, branch `develop`. It uses crelish through a symlink (`composer.local.json` path repo), so crelish changes are live locally. Task 9 and parts of 13 happen here. `composer.lock` is not tracked there; production resolves from Packagist at deploy.

## Global Constraints

- PHP `>=8.2.0` (crelish `composer.json`); match the surrounding file's indentation (crelish components use 2 spaces, some controllers 4 — follow the file).
- `state`: 0 Offline, 1 Draft, 2 Online, 3 Archived. Only 2 redirects to the target.
- Generated codes: 6 characters from `23456789abcdefghjkmnpqrstuvwxyz`. Custom codes: `^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$`, at least 2 characters, stored lowercase.
- Built-in reserved codes: `crelish`, `api`, `crelish-api`, `site`, `sitemap`, `robots`, `favicon`, `assets`, `q`, `document`, plus the configured prefix, plus `reservedCodes` from config.
- Redirects: HTTP 302, `Cache-Control: no-store`, `X-Robots-Tag: noindex`. The destination never comes from request parameters.
- `target_url` and `fallback_url` must be absolute `http`/`https` URLs.
- Analytics: `element_type = 'shortlink'`; `type` is `scan`, `click` or `fallback`; **`page_uuid` = the link's uuid** (the column is NOT NULL in production).
- `analytics_sessions.user_agent` and `first_url` are `varchar(255)` in production: truncate before insert.
- QR payload: the short URL uppercased plus `/Q` (QR alphanumeric mode). Error correction M for plain codes, H for logo codes. The logo knockout takes at most 22 % of the code width. PNG at least 300 dpi. The size in mm includes the quiet zone. Minimum sizes: plain 15 mm, logo 25 mm.
- Config lives in `Yii::$app->params['crelish']['shortLinks']`. Defaults: `enabled` false, `prefix` `'go'`, `shortHost` null, `siteUrl` null, `fallbackUrl` null, `detailPages` `[]`, `qrLogo` null, `reservedCodes` `[]`.
- Tests are standalone scripts run with `php tests/<Name>.php` from the crelish root; each prints `ok`/`FAIL` lines and exits non-zero on failure (the same style as `tests/ClickTrackingSecurityTest.php`).
- Commit messages use the repo's conventional style, e.g. `feat(shortlinks): …`. Commit on `feature/shortlinks`; never push or release without the user's go-ahead.

## Review Focus

1. A scanned URL arrives fully uppercase and with a trailing slash (`/GO/IHF26/Q/`): it must resolve like `/go/ihf26/q` → pinned in Task 2.
2. A real-world user agent or first URL longer than 255 characters: the hit must be recorded truncated, not lost → pinned in Task 4.
3. A news record whose `to` field is a date without time (`2026-09-24`): the link stays valid until the end of that day → pinned in Task 3.
4. A logo PNG with transparency: PDF export must not fail (FPDF cannot embed alpha) → pinned in Task 6.
5. An editor types a code that differs only by case from an existing one (`IHF26` vs `ihf26`): it must be rejected as a duplicate → pinned in Task 1.

---

## File Structure

crelish (new unless noted):

| File | Responsibility |
|---|---|
| `migrations/m260924_120000_create_shortlink_table.php` | table + indexes |
| `models/ShortLink.php` | AR model: validation, status, short URL, QR payload, admin form loading |
| `components/shortlinks/ShortLinkConfig.php` | reads `params.crelish.shortLinks` with defaults; site/home/fallback URLs |
| `components/shortlinks/ShortLinkCode.php` | code generation, normalisation and format rules |
| `components/shortlinks/ShortLinkUrlRule.php` | parses `/go/<code>[/q]` and short-host paths; `register()` prepends it |
| `components/shortlinks/ShortLinkTargetInterface.php` | escape hatch for models with custom detail URLs |
| `components/shortlinks/ResolveResult.php` | destination URL + reason |
| `components/shortlinks/ShortLinkResolver.php` | state/validity checks, content resolution, language, fallback chain |
| `components/shortlinks/ShortLinkStats.php` | per-link statistics from daily aggregates + raw events |
| `components/shortlinks/qr/QrMatrix.php` | encodes the payload with bacon into a boolean grid |
| `components/shortlinks/qr/QrLogo.php` | loads PNG/JPEG logos, flattened onto white |
| `components/shortlinks/qr/QrPdf.php` | FPDF subclass that fills many rectangles as one path |
| `components/shortlinks/qr/QrRenderer.php` | SVG/EPS/PDF/PNG output at exact mm size |
| `components/shortlinks/QrBundleService.php` | file set, README, ZIP, logo path lookup |
| `controllers/ShortLinkRedirectController.php` | public redirect + tracking |
| `controllers/ShortLinkController.php` | admin list/edit/delete/targets/QR/stats |
| `views/short-link/index.twig`, `views/short-link/edit.twig` | admin views |
| `components/CrelishAnalyticsComponent.php` (modify) | add `trackEvent()` |
| `components/ElementTitleResolver.php` (modify) | built-in `shortlink` title lookup |
| `components/CrelishSidebarManager.php` (modify) | `shortlinks` condition |
| `config/sidebar.json` (modify) | "Short Links" item |
| `Bootstrap.php` (modify) | register the URL rule |
| `composer.json` (modify) | bacon, fpdf, ext-gd, ext-zip |
| `docs/shortlinks.md`, `docs/README.md` (modify) | documentation |
| `tests/shortlink/bootstrap.php` | shared test app (SQLite, stubs, `check()`) |
| `tests/ShortLink*Test.php`, `tests/Qr*Test.php` | tests |

---

### Task 1: Model, migration, config and code rules

**Files:**
- Create: `tests/shortlink/bootstrap.php`
- Create: `tests/ShortLinkCodeTest.php`
- Create: `migrations/m260924_120000_create_shortlink_table.php`
- Create: `components/shortlinks/ShortLinkConfig.php`
- Create: `components/shortlinks/ShortLinkCode.php`
- Create: `models/ShortLink.php`

**Interfaces:**
- Produces:
  - `shortLinkApp(array $params = [], array $server = [], array $config = []): \yii\web\Application` and `check(string $name, mixed $expected, mixed $actual): void`, `shortLinkDone(): never` (test bootstrap)
  - `ShortLinkConfig::all(): array`, `isEnabled(): bool`, `prefix(): string`, `shortHost(): ?string`, `siteUrl(): string`, `homeUrl(): string`, `siteFallbackUrl(): string`, `absolute(string $url): string`, `detailPages(): array`, `qrLogoPath(): ?string`, `reservedCodes(): array`
  - `ShortLinkCode::ALPHABET`, `LENGTH`, `random(): string`, `generate(callable $exists, int $maxAttempts = 20): string`, `normalize(?string $code): string`, `formatError(string $code): ?string`
  - `ShortLink` constants `STATE_*`, `TARGET_URL`, `TARGET_CONTENT`, `STATUS_*`; methods `getStatus(?int $now = null): string`, `isLive(?int $now = null): bool`, `getShortUrl(bool $qr = false): string`, `getQrPayload(): string`, `loadForm(array $post): bool`, `static findByCode(string $code): ?ShortLink`; virtual attributes `validFromInput`, `validUntilInput`

- [ ] **Step 1: Write the shared test bootstrap**

`tests/shortlink/bootstrap.php`:

```php
<?php

/**
 * Shared harness for the short link tests.
 *
 * Builds a fresh web application per scenario with an in-memory SQLite
 * database, the real shortlink migration and the analytics tables in their
 * production shape (page_uuid NOT NULL, varchar(255) user agent/first URL).
 */

declare(strict_types=1);

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'test');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

use giantbits\crelish\components\CrelishAnalyticsComponent;
use giantbits\crelish\migrations\m260924_120000_create_shortlink_table;
use yii\helpers\ArrayHelper;

Yii::setAlias('@giantbits/crelish', dirname(__DIR__, 2));

/**
 * Session stub so analytics and flash messages work without PHP session state
 */
class ShortLinkTestSession extends \yii\web\Session
{
    private array $data = [];

    public function open(): void
    {
    }

    public function close(): void
    {
    }

    public function getIsActive(): bool
    {
        return true;
    }

    public function get($key, $defaultValue = null)
    {
        return $this->data[$key] ?? $defaultValue;
    }

    public function set($key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function has($key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove($key)
    {
        $value = $this->data[$key] ?? null;
        unset($this->data[$key]);
        return $value;
    }
}

/**
 * Identity stub; tests always run as a guest
 */
class ShortLinkTestIdentity implements \yii\web\IdentityInterface
{
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
        return null;
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

/**
 * Build a fresh application.
 *
 * @param array $params  merged into params['crelish'] (shortLinks is enabled by default)
 * @param array $server  $_SERVER overrides (HTTP_HOST, REQUEST_URI, HTTP_USER_AGENT, ...)
 * @param array $config  merged into the application config
 */
function shortLinkApp(array $params = [], array $server = [], array $config = []): \yii\web\Application
{
    $_GET = [];
    $_SERVER = array_merge($_SERVER, [
        'HTTP_HOST' => 'forum-holzbau.test',
        'SERVER_NAME' => 'forum-holzbau.test',
        'SERVER_PORT' => '443',
        'HTTPS' => 'on',
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/',
        'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => __FILE__,
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
        'HTTP_ACCEPT_LANGUAGE' => '',
    ], $server);

    $crelish = array_replace_recursive([
        'langprefix' => true,
        'languages' => ['de'],
        'entryPoint' => ['ctype' => 'page', 'path' => 'home', 'slug' => 'home'],
        'shortLinks' => ['enabled' => true],
    ], $params);

    $app = new \yii\web\Application(ArrayHelper::merge([
        'id' => 'shortlink-test',
        'basePath' => dirname(__DIR__, 2),
        'language' => 'de',
        'params' => ['crelish' => $crelish],
        'components' => [
            'request' => [
                'cookieValidationKey' => 'shortlink-test-key',
                'scriptUrl' => '/index.php',
                'scriptFile' => __FILE__,
            ],
            'session' => ['class' => ShortLinkTestSession::class],
            'user' => ['identityClass' => ShortLinkTestIdentity::class, 'enableSession' => false],
            'db' => [
                'class' => \yii\db\Connection::class,
                'dsn' => 'sqlite::memory:',
                'pdoClass' => class_exists(\Pdo\Sqlite::class) ? \Pdo\Sqlite::class : null,
                'on afterOpen' => static function ($event): void {
                    shortLinkSqliteFunctions($event->sender->pdo);
                },
            ],
            'cache' => ['class' => \yii\caching\ArrayCache::class],
            'urlManager' => ['enablePrettyUrl' => true, 'showScriptName' => false],
            'crelishAnalytics' => ['class' => CrelishAnalyticsComponent::class],
        ],
    ], $config));

    shortLinkSchema($app->db);

    return $app;
}

/**
 * MySQL's NOW() is used by the analytics inserts; SQLite needs it registered.
 */
function shortLinkSqliteFunctions(\PDO $pdo): void
{
    $now = static fn(): string => date('Y-m-d H:i:s');

    if ($pdo instanceof \Pdo\Sqlite) {
        $pdo->createFunction('NOW', $now, 0);
        return;
    }

    $pdo->sqliteCreateFunction('NOW', $now, 0);
}

/**
 * Real shortlink migration plus the analytics tables in their production shape.
 */
function shortLinkSchema(\yii\db\Connection $db): void
{
    (new m260924_120000_create_shortlink_table(['db' => $db, 'compact' => true]))->up();

    $db->createCommand()->createTable('analytics_sessions', [
        'session_id' => 'varchar(100) NOT NULL PRIMARY KEY',
        'user_id' => 'integer NULL',
        'ip_address' => 'varchar(45) NULL',
        'user_agent' => 'varchar(255) NULL',
        'is_bot' => 'smallint DEFAULT 0',
        'first_page_uuid' => 'varchar(36) NULL',
        'first_url' => 'varchar(255) NULL',
        'created_at' => 'datetime DEFAULT CURRENT_TIMESTAMP',
        'last_activity' => 'datetime DEFAULT CURRENT_TIMESTAMP',
        'total_pages' => 'integer DEFAULT 1',
    ])->execute();

    $db->createCommand()->createTable('analytics_element_views', [
        'id' => 'integer PRIMARY KEY AUTOINCREMENT',
        'element_uuid' => 'varchar(36) NOT NULL',
        'element_type' => 'varchar(50) NOT NULL',
        'page_uuid' => 'varchar(36) NOT NULL',
        'session_id' => 'varchar(100) NULL',
        'user_id' => 'integer NULL',
        'type' => 'varchar(255) NULL',
        'created_at' => 'datetime DEFAULT CURRENT_TIMESTAMP',
    ])->execute();

    $db->createCommand()->createTable('analytics_element_daily', [
        'id' => 'integer PRIMARY KEY AUTOINCREMENT',
        'date' => 'date NOT NULL',
        'element_uuid' => 'varchar(36) NOT NULL',
        'element_type' => 'varchar(50) NOT NULL',
        'page_uuid' => 'varchar(36) NULL',
        'event_type' => 'varchar(50) NOT NULL',
        'total_views' => 'integer DEFAULT 0',
        'unique_sessions' => 'integer DEFAULT 0',
        'unique_users' => 'integer DEFAULT 0',
    ])->execute();
}

$failures = 0;
$passed = 0;

function check(string $name, mixed $expected, mixed $actual): void
{
    global $failures, $passed;

    if ($expected === $actual) {
        $passed++;
        echo "  ok   $name\n";
        return;
    }

    $failures++;
    echo "  FAIL $name\n";
    echo "         expected: " . var_export($expected, true) . "\n";
    echo "         actual:   " . var_export($actual, true) . "\n";
}

function shortLinkDone(): never
{
    global $failures, $passed;

    echo "\n$passed passed, $failures failed\n";
    exit($failures === 0 ? 0 : 1);
}
```

- [ ] **Step 2: Write the failing test**

`tests/ShortLinkCodeTest.php`:

```php
<?php

/**
 * Short link codes and the ShortLink model: code rules, validation,
 * status, short URLs and QR payload.
 *
 * Run with:  php tests/ShortLinkCodeTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\shortlinks\ShortLinkCode;
use giantbits\crelish\models\ShortLink;

function makeLink(array $attributes = []): ShortLink
{
    $link = new ShortLink();
    $link->setAttributes(array_merge([
        'systitle' => 'IHF 2026 Flyer',
        'code' => 'ihf26',
        'target_type' => ShortLink::TARGET_URL,
        'target_url' => 'https://www.example.com/ihf',
        'state' => ShortLink::STATE_ONLINE,
    ], $attributes), false);

    return $link;
}

echo "Short link codes\n";
shortLinkApp();

$bad = [];
for ($i = 0; $i < 200; $i++) {
    $code = ShortLinkCode::random();
    if (strlen($code) !== ShortLinkCode::LENGTH || strspn($code, ShortLinkCode::ALPHABET) !== ShortLinkCode::LENGTH) {
        $bad[] = $code;
    }
}
check('generated codes are 6 characters from the unambiguous alphabet', [], $bad);

$calls = 0;
ShortLinkCode::generate(function () use (&$calls): bool {
    return ++$calls <= 3;
});
check('generator retries until a free code is found', 4, $calls);

try {
    ShortLinkCode::generate(fn(): bool => true, 5);
    $threw = false;
} catch (RuntimeException) {
    $threw = true;
}
check('generator gives up after max attempts', true, $threw);

check('normalize lowercases and trims', 'ihf26', ShortLinkCode::normalize('  IHF26 '));
check('two characters are allowed', null, ShortLinkCode::formatError('ab'));
check('one character is rejected', true, ShortLinkCode::formatError('a') !== null);
check('leading hyphen is rejected', true, ShortLinkCode::formatError('-ab') !== null);
check('trailing hyphen is rejected', true, ShortLinkCode::formatError('ab-') !== null);
check('underscore is rejected', true, ShortLinkCode::formatError('ab_c') !== null);
check('64 characters are allowed', null, ShortLinkCode::formatError(str_repeat('a', 64)));
check('65 characters are rejected', true, ShortLinkCode::formatError(str_repeat('a', 65)) !== null);
check('built-in reserved word is rejected', true, ShortLinkCode::formatError('crelish') !== null);
check('the prefix itself is reserved', true, ShortLinkCode::formatError('go') !== null);

shortLinkApp(['shortLinks' => ['reservedCodes' => ['Messe']]]);
check('configured reserved codes are compared lowercase', true, ShortLinkCode::formatError('messe') !== null);

echo "\nShortLink validation\n";
shortLinkApp();

$link = makeLink();
check('valid external link saves', true, $link->save());
check('uuid is generated', 36, strlen((string) $link->uuid));
check('created timestamp is set', true, (int) $link->created > 0);

foreach ([
    'javascript scheme' => 'javascript:alert(1)',
    'data scheme' => 'data:text/html,<script>alert(1)</script>',
    'relative url' => '/de/programm',
    'ftp scheme' => 'ftp://example.com/file',
] as $label => $url) {
    $candidate = makeLink(['code' => 'x' . substr(md5($label), 0, 5), 'target_url' => $url]);
    check("target_url rejects $label", true, !$candidate->validate() && $candidate->hasErrors('target_url'));
    $candidate = makeLink(['code' => 'y' . substr(md5($label), 0, 5), 'fallback_url' => $url]);
    check("fallback_url rejects $label", true, !$candidate->validate() && $candidate->hasErrors('fallback_url'));
}

$candidate = makeLink(['code' => 'IHF-27']);
$candidate->validate();
check('code is normalised to lowercase on validation', 'ihf-27', $candidate->code);

$duplicate = makeLink(['code' => 'IHF26']);
check('code differing only by case is a duplicate', true, !$duplicate->validate() && $duplicate->hasErrors('code'));

$reserved = makeLink(['code' => 'sitemap']);
check('reserved code fails validation', true, !$reserved->validate() && $reserved->hasErrors('code'));

$content = makeLink(['code' => 'cnt01', 'target_type' => ShortLink::TARGET_CONTENT, 'target_url' => null]);
$content->validate();
check('content target requires ctype', true, $content->hasErrors('target_ctype'));
check('content target requires uuid', true, $content->hasErrors('target_uuid'));

$window = makeLink(['code' => 'win01', 'valid_from' => 2000, 'valid_until' => 1000]);
check('valid_until before valid_from is rejected', true, !$window->validate() && $window->hasErrors('valid_until'));

$qr = makeLink(['code' => 'qr001', 'qr_quiet_zone' => 3, 'qr_color' => 'red', 'qr_size_mm' => 5]);
$qr->validate();
check('quiet zone below 4 modules is rejected', true, $qr->hasErrors('qr_quiet_zone'));
check('colour must be #rrggbb', true, $qr->hasErrors('qr_color'));
check('size below 10 mm is rejected', true, $qr->hasErrors('qr_size_mm'));

$dates = makeLink(['code' => 'dat01']);
$dates->validFromInput = '2026-10-01T09:00';
check('validFromInput sets a timestamp', strtotime('2026-10-01 09:00'), $dates->valid_from);
check('validFromInput formats back', '2026-10-01T09:00', $dates->validFromInput);
$dates->validFromInput = '';
check('empty validFromInput clears the timestamp', null, $dates->valid_from);

$switch = makeLink(['code' => 'sw001', 'target_type' => ShortLink::TARGET_CONTENT, 'target_ctype' => 'news', 'target_uuid' => str_repeat('a', 36)]);
check('content link saves', true, $switch->save());
check('saving a content link clears target_url', null, $switch->target_url);

$form = makeLink(['code' => 'frm01']);
$loaded = $form->loadForm([
    'ShortLink' => ['systitle' => 'Changed', 'validUntilInput' => '2026-12-31T23:59'],
    'CrelishDynamicModel' => ['logo_asset_uuid' => 'b1946ac9-2d6e-4c5f-8a3c-2f1c5f3e9a10'],
]);
check('loadForm reports loaded', true, $loaded);
check('loadForm assigns fields', 'Changed', $form->systitle);
check('loadForm converts the date input', strtotime('2026-12-31 23:59'), $form->valid_until);
check('loadForm takes the logo from the asset connector field', 'b1946ac9-2d6e-4c5f-8a3c-2f1c5f3e9a10', $form->logo_asset_uuid);
$form->loadForm(['CrelishDynamicModel' => ['logo_asset_uuid' => '']]);
check('an emptied asset connector clears the logo', null, $form->logo_asset_uuid);

echo "\nShortLink status\n";
$now = strtotime('2026-09-24 12:00');
check('online without window', ShortLink::STATUS_ONLINE, makeLink()->getStatus($now));
check('scheduled before valid_from', ShortLink::STATUS_SCHEDULED, makeLink(['valid_from' => $now + 60])->getStatus($now));
check('expired after valid_until', ShortLink::STATUS_EXPIRED, makeLink(['valid_until' => $now - 60])->getStatus($now));
check('offline state', ShortLink::STATUS_OFFLINE, makeLink(['state' => 0])->getStatus($now));
check('draft state', ShortLink::STATUS_DRAFT, makeLink(['state' => 1])->getStatus($now));
check('archived state', ShortLink::STATUS_ARCHIVED, makeLink(['state' => 3])->getStatus($now));
check('only online is live', false, makeLink(['state' => 1])->isLive($now));
check('online is live', true, makeLink()->isLive($now));

echo "\nShort URLs\n";
$saved = ShortLink::findByCode('IHF26');
check('findByCode is case-insensitive', 'ihf26', $saved?->code);
check('short URL on the site host', 'https://forum-holzbau.test/go/ihf26', $saved->getShortUrl());
check('QR short URL carries /q', 'https://forum-holzbau.test/go/ihf26/q', $saved->getShortUrl(true));
check('QR payload is uppercase', 'HTTPS://FORUM-HOLZBAU.TEST/GO/IHF26/Q', $saved->getQrPayload());

shortLinkApp(['shortLinks' => ['siteUrl' => 'https://forum-holzbau.com/']]);
check('configured siteUrl wins over the request host', 'https://forum-holzbau.com/go/ihf26', makeLink()->getShortUrl());

shortLinkApp(['shortLinks' => ['shortHost' => 'FHB.link']]);
check('short host drops the prefix', 'https://fhb.link/ihf26', makeLink()->getShortUrl());
check('short host QR payload', 'HTTPS://FHB.LINK/IHF26/Q', makeLink()->getQrPayload());

shortLinkDone();
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php tests/ShortLinkCodeTest.php`
Expected: a fatal error `Class "giantbits\crelish\migrations\m260924_120000_create_shortlink_table" not found`.

- [ ] **Step 4: Write the migration**

`migrations/m260924_120000_create_shortlink_table.php`:

```php
<?php
namespace giantbits\crelish\migrations;

use yii\db\Migration;

/**
 * Class m260924_120000_create_shortlink_table
 *
 * Table behind the built-in short link feature (campaign and print links).
 * Uses the standard crelish columns (uuid, created, updated, created_by,
 * updated_by, state, systitle) so generic tooling keeps working.
 */
class m260924_120000_create_shortlink_table extends Migration
{
  public function safeUp()
  {
    $tableOptions = $this->db->driverName === 'mysql'
      ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
      : null;

    $this->createTable('{{%shortlink}}', [
      'uuid' => $this->string(36)->notNull()->append('PRIMARY KEY'),
      'created' => $this->integer()->null(),
      'updated' => $this->integer()->null(),
      'created_by' => $this->string(36)->null(),
      'updated_by' => $this->string(36)->null(),
      'state' => $this->smallInteger()->notNull()->defaultValue(1),
      'systitle' => $this->string(255)->notNull(),
      'code' => $this->string(64)->notNull(),
      'target_type' => $this->string(16)->notNull(),
      'target_url' => $this->text()->null(),
      'target_ctype' => $this->string(64)->null(),
      'target_uuid' => $this->string(36)->null(),
      'target_language' => $this->string(8)->null(),
      'fallback_url' => $this->text()->null(),
      'valid_from' => $this->integer()->null(),
      'valid_until' => $this->integer()->null(),
      'note' => $this->text()->null(),
      'logo_asset_uuid' => $this->string(36)->null(),
      'qr_size_mm' => $this->smallInteger()->notNull()->defaultValue(30),
      'qr_color' => $this->string(7)->notNull()->defaultValue('#000000'),
      'qr_quiet_zone' => $this->smallInteger()->notNull()->defaultValue(4),
    ], $tableOptions);

    $this->createIndex('idx-shortlink-code', '{{%shortlink}}', 'code', true);
    $this->createIndex('idx-shortlink-state', '{{%shortlink}}', 'state');
    $this->createIndex('idx-shortlink-target', '{{%shortlink}}', ['target_ctype', 'target_uuid']);
  }

  public function safeDown()
  {
    $this->dropTable('{{%shortlink}}');
  }
}
```

- [ ] **Step 5: Write `ShortLinkConfig`**

`components/shortlinks/ShortLinkConfig.php`:

```php
<?php

namespace giantbits\crelish\components\shortlinks;

use giantbits\crelish\components\CrelishBaseHelper;
use Yii;

/**
 * Reads the short link settings from params['crelish']['shortLinks'].
 */
final class ShortLinkConfig
{
  public const DEFAULTS = [
    'enabled' => false,
    'prefix' => 'go',
    'shortHost' => null,
    'siteUrl' => null,
    'fallbackUrl' => null,
    'detailPages' => [],
    'qrLogo' => null,
    'reservedCodes' => [],
  ];

  public const BUILTIN_RESERVED = ['crelish', 'api', 'crelish-api', 'site', 'sitemap', 'robots', 'favicon', 'assets', 'q', 'document'];

  public static function all(): array
  {
    $config = Yii::$app->params['crelish']['shortLinks'] ?? [];

    return array_merge(self::DEFAULTS, is_array($config) ? $config : []);
  }

  public static function isEnabled(): bool
  {
    return (bool)self::all()['enabled'];
  }

  public static function prefix(): string
  {
    return strtolower(trim((string)self::all()['prefix'], '/'));
  }

  public static function shortHost(): ?string
  {
    $host = self::all()['shortHost'];

    return $host ? strtolower((string)$host) : null;
  }

  /**
   * Absolute base URL of the main site, without trailing slash
   */
  public static function siteUrl(): string
  {
    $url = self::all()['siteUrl'] ?: Yii::$app->request->hostInfo;

    return rtrim((string)$url, '/');
  }

  /**
   * Absolute URL of the site's home page in the default language
   */
  public static function homeUrl(): string
  {
    $slug = Yii::$app->params['crelish']['entryPoint']['slug'] ?? 'home';

    return self::absolute(CrelishBaseHelper::urlFromSlug($slug));
  }

  public static function siteFallbackUrl(): string
  {
    $url = self::all()['fallbackUrl'];

    return $url ? self::absolute((string)$url) : self::homeUrl();
  }

  /**
   * Make a site-relative URL absolute on the main site; absolute URLs pass through
   */
  public static function absolute(string $url): string
  {
    if (str_starts_with($url, '//')) {
      return 'https:' . $url;
    }

    if (str_starts_with($url, '/')) {
      return self::siteUrl() . $url;
    }

    return $url;
  }

  /**
   * @return array<string,string> ctype => slug of the listing page that hosts its detail views
   */
  public static function detailPages(): array
  {
    $pages = self::all()['detailPages'];

    return is_array($pages) ? $pages : [];
  }

  public static function qrLogoPath(): ?string
  {
    $path = self::all()['qrLogo'];

    return $path ? Yii::getAlias((string)$path) : null;
  }

  /**
   * @return string[] lowercase codes that can never be used
   */
  public static function reservedCodes(): array
  {
    $custom = array_map(static fn($code) => strtolower((string)$code), (array)self::all()['reservedCodes']);

    return array_values(array_unique(array_merge(self::BUILTIN_RESERVED, [self::prefix()], $custom)));
  }
}
```

- [ ] **Step 6: Write `ShortLinkCode`**

`components/shortlinks/ShortLinkCode.php`:

```php
<?php

namespace giantbits\crelish\components\shortlinks;

use RuntimeException;

/**
 * Generates and checks short link codes.
 *
 * The alphabet leaves out characters that are easily confused when a code is
 * typed from paper (0/o, 1/l/i).
 */
final class ShortLinkCode
{
  public const ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';
  public const LENGTH = 6;
  public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

  public static function normalize(?string $code): string
  {
    return strtolower(trim((string)$code));
  }

  public static function random(): string
  {
    $max = strlen(self::ALPHABET) - 1;
    $code = '';

    for ($i = 0; $i < self::LENGTH; $i++) {
      $code .= self::ALPHABET[random_int(0, $max)];
    }

    return $code;
  }

  /**
   * @param callable(string): bool $exists returns true when a code is already taken
   */
  public static function generate(callable $exists, int $maxAttempts = 20): string
  {
    $reserved = ShortLinkConfig::reservedCodes();

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
      $code = self::random();

      if (!in_array($code, $reserved, true) && !$exists($code)) {
        return $code;
      }
    }

    throw new RuntimeException('Could not generate a unique short link code.');
  }

  /**
   * @return string|null an error message, or null when the (normalised) code is acceptable
   */
  public static function formatError(string $code): ?string
  {
    if (strlen($code) < 2 || !preg_match(self::PATTERN, $code)) {
      return 'Use 2 to 64 characters: a-z, 0-9 and hyphens, not at the start or end.';
    }

    if (in_array($code, ShortLinkConfig::reservedCodes(), true)) {
      return 'This code is reserved.';
    }

    return null;
  }
}
```

- [ ] **Step 7: Write the model**

`models/ShortLink.php`:

```php
<?php

namespace giantbits\crelish\models;

use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\shortlinks\ShortLinkCode;
use giantbits\crelish\components\shortlinks\ShortLinkConfig;
use Yii;
use yii\behaviors\AttributeBehavior;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A campaign short link: /go/<code> redirects to an external URL or a crelish record.
 *
 * @property string $uuid
 * @property int|null $created
 * @property int|null $updated
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property int $state
 * @property string $systitle
 * @property string $code
 * @property string $target_type
 * @property string|null $target_url
 * @property string|null $target_ctype
 * @property string|null $target_uuid
 * @property string|null $target_language
 * @property string|null $fallback_url
 * @property int|null $valid_from
 * @property int|null $valid_until
 * @property string|null $note
 * @property string|null $logo_asset_uuid
 * @property int $qr_size_mm
 * @property string $qr_color
 * @property int $qr_quiet_zone
 * @property string $validFromInput
 * @property string $validUntilInput
 */
class ShortLink extends ActiveRecord
{
  public const STATE_OFFLINE = 0;
  public const STATE_DRAFT = 1;
  public const STATE_ONLINE = 2;
  public const STATE_ARCHIVED = 3;

  public const TARGET_URL = 'url';
  public const TARGET_CONTENT = 'content';

  public const STATUS_ONLINE = 'online';
  public const STATUS_OFFLINE = 'offline';
  public const STATUS_DRAFT = 'draft';
  public const STATUS_ARCHIVED = 'archived';
  public const STATUS_SCHEDULED = 'scheduled';
  public const STATUS_EXPIRED = 'expired';

  private const NULLABLE = ['target_url', 'target_ctype', 'target_uuid', 'target_language', 'fallback_url', 'note', 'logo_asset_uuid'];

  public static function tableName()
  {
    return '{{%shortlink}}';
  }

  public static function primaryKey()
  {
    return ['uuid'];
  }

  public function behaviors()
  {
    return [
      'uuid' => [
        'class' => AttributeBehavior::class,
        'attributes' => [ActiveRecord::EVENT_BEFORE_INSERT => 'uuid'],
        'value' => fn() => $this->uuid ?: CrelishBaseHelper::GUIDv4(),
      ],
      'timestamp' => [
        'class' => TimestampBehavior::class,
        'createdAtAttribute' => 'created',
        'updatedAtAttribute' => 'updated',
      ],
      'blameable' => [
        'class' => BlameableBehavior::class,
        'createdByAttribute' => 'created_by',
        'updatedByAttribute' => 'updated_by',
      ],
    ];
  }

  public function rules()
  {
    return [
      [['systitle', 'code', 'target_type'], 'required'],
      ['code', 'filter', 'filter' => [ShortLinkCode::class, 'normalize']],
      ['code', 'validateCode'],
      ['code', 'unique'],
      ['systitle', 'string', 'max' => 255],
      ['target_type', 'in', 'range' => [self::TARGET_URL, self::TARGET_CONTENT]],
      [['target_url', 'fallback_url'], 'filter', 'filter' => 'trim', 'skipOnEmpty' => true],
      [['target_url', 'fallback_url'], 'url', 'validSchemes' => ['http', 'https']],
      ['target_url', 'required', 'when' => fn(self $model) => $model->target_type === self::TARGET_URL],
      [['target_ctype', 'target_uuid'], 'required', 'when' => fn(self $model) => $model->target_type === self::TARGET_CONTENT],
      ['target_ctype', 'string', 'max' => 64],
      [['target_uuid', 'logo_asset_uuid'], 'string', 'max' => 36],
      ['target_language', 'string', 'max' => 8],
      ['state', 'default', 'value' => self::STATE_DRAFT],
      ['state', 'filter', 'filter' => 'intval'],
      ['state', 'in', 'range' => [self::STATE_OFFLINE, self::STATE_DRAFT, self::STATE_ONLINE, self::STATE_ARCHIVED]],
      [['valid_from', 'valid_until'], 'integer'],
      ['valid_until', 'compare', 'compareAttribute' => 'valid_from', 'operator' => '>', 'type' => 'number',
        'when' => fn(self $model) => $model->valid_from !== null && $model->valid_from !== ''],
      ['note', 'string'],
      ['qr_size_mm', 'default', 'value' => 30],
      ['qr_size_mm', 'integer', 'min' => 10, 'max' => 500],
      ['qr_color', 'default', 'value' => '#000000'],
      ['qr_color', 'match', 'pattern' => '/^#[0-9a-fA-F]{6}$/'],
      ['qr_quiet_zone', 'default', 'value' => 4],
      ['qr_quiet_zone', 'integer', 'min' => 4, 'max' => 20],
      [['validFromInput', 'validUntilInput'], 'safe'],
    ];
  }

  public function validateCode(string $attribute): void
  {
    $error = ShortLinkCode::formatError((string)$this->$attribute);

    if ($error !== null) {
      $this->addError($attribute, Yii::t('crelish', $error));
    }
  }

  public function beforeSave($insert)
  {
    if ($this->target_type === self::TARGET_URL) {
      $this->target_ctype = null;
      $this->target_uuid = null;
      $this->target_language = null;
    } else {
      $this->target_url = null;
    }

    foreach (self::NULLABLE as $attribute) {
      if ($this->$attribute === '') {
        $this->$attribute = null;
      }
    }

    return parent::beforeSave($insert);
  }

  /**
   * Load the admin form: ShortLink[...] plus the asset connector's logo field,
   * which always posts as CrelishDynamicModel[logo_asset_uuid].
   */
  public function loadForm(array $post): bool
  {
    $loaded = $this->load($post);
    $asset = $post['CrelishDynamicModel']['logo_asset_uuid'] ?? null;

    if ($asset !== null) {
      $this->logo_asset_uuid = $asset === '' ? null : (string)$asset;
      $loaded = true;
    }

    return $loaded;
  }

  public function getStatus(?int $now = null): string
  {
    $now ??= time();

    switch ((int)$this->state) {
      case self::STATE_OFFLINE:
        return self::STATUS_OFFLINE;
      case self::STATE_DRAFT:
        return self::STATUS_DRAFT;
      case self::STATE_ARCHIVED:
        return self::STATUS_ARCHIVED;
    }

    if ($this->valid_from !== null && $this->valid_from !== '' && $now < (int)$this->valid_from) {
      return self::STATUS_SCHEDULED;
    }

    if ($this->valid_until !== null && $this->valid_until !== '' && $now > (int)$this->valid_until) {
      return self::STATUS_EXPIRED;
    }

    return self::STATUS_ONLINE;
  }

  public function isLive(?int $now = null): bool
  {
    return $this->getStatus($now) === self::STATUS_ONLINE;
  }

  public function getShortUrl(bool $qr = false): string
  {
    $path = $this->code . ($qr ? '/q' : '');
    $host = ShortLinkConfig::shortHost();

    if ($host !== null) {
      return 'https://' . $host . '/' . $path;
    }

    return ShortLinkConfig::siteUrl() . '/' . ShortLinkConfig::prefix() . '/' . $path;
  }

  /**
   * The QR content: uppercase so the code uses the denser-packing alphanumeric mode
   */
  public function getQrPayload(): string
  {
    return strtoupper($this->getShortUrl(true));
  }

  public function getValidFromInput(): string
  {
    return self::formatInput($this->valid_from);
  }

  public function setValidFromInput($value): void
  {
    $this->valid_from = self::parseInput($value);
  }

  public function getValidUntilInput(): string
  {
    return self::formatInput($this->valid_until);
  }

  public function setValidUntilInput($value): void
  {
    $this->valid_until = self::parseInput($value);
  }

  public static function findByCode(string $code): ?self
  {
    return static::findOne(['code' => ShortLinkCode::normalize($code)]);
  }

  private static function formatInput($timestamp): string
  {
    return $timestamp ? date('Y-m-d\TH:i', (int)$timestamp) : '';
  }

  private static function parseInput($value): ?int
  {
    $value = trim((string)$value);

    if ($value === '') {
      return null;
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? null : $timestamp;
  }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php tests/ShortLinkCodeTest.php`
Expected: every line `ok`, last line `N passed, 0 failed`, exit code 0.

- [ ] **Step 9: Commit**

```bash
git add tests/shortlink/bootstrap.php tests/ShortLinkCodeTest.php migrations/m260924_120000_create_shortlink_table.php components/shortlinks/ShortLinkConfig.php components/shortlinks/ShortLinkCode.php models/ShortLink.php
git commit -m "feat(shortlinks): short link model, migration and code rules"
```

---

### Task 2: URL rule and registration

**Files:**
- Create: `components/shortlinks/ShortLinkUrlRule.php`
- Modify: `Bootstrap.php` (method `configureUrlRules`, add a `use` line)
- Test: `tests/ShortLinkUrlRuleTest.php`

**Interfaces:**
- Consumes: `ShortLinkConfig::isEnabled()`, `prefix()`, `shortHost()`; test bootstrap
- Produces: `ShortLinkUrlRule::ROUTE = 'crelish/short-link-redirect/index'`, `HOME_ROUTE = 'crelish/short-link-redirect/home'`, `static register(\yii\web\UrlManager $manager): void`. Route params: `code` (lowercase string), `scan` (int 0|1)

- [ ] **Step 1: Write the failing test**

`tests/ShortLinkUrlRuleTest.php`:

```php
<?php

/**
 * Short link URL parsing: prefix route, scan marker, case, short host,
 * and that the rule is registered ahead of catch-all page rules.
 *
 * Run with:  php tests/ShortLinkUrlRuleTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\shortlinks\ShortLinkUrlRule;

function parse(string $path, string $host = 'forum-holzbau.test', array $params = []): array|false
{
    shortLinkApp($params, ['HTTP_HOST' => $host, 'SERVER_NAME' => $host, 'REQUEST_URI' => $path]);

    return (new ShortLinkUrlRule())->parseRequest(Yii::$app->urlManager, Yii::$app->request);
}

$route = ShortLinkUrlRule::ROUTE;
$home = ShortLinkUrlRule::HOME_ROUTE;

echo "Prefix route\n";
check('/go/ihf26 is a click', [$route, ['code' => 'ihf26', 'scan' => 0]], parse('/go/ihf26'));
check('/go/ihf26/q is a scan', [$route, ['code' => 'ihf26', 'scan' => 1]], parse('/go/ihf26/q'));
check('uppercase scan URL', [$route, ['code' => 'ihf26', 'scan' => 1]], parse('/GO/IHF26/Q'));
check('uppercase scan URL with trailing slash', [$route, ['code' => 'ihf26', 'scan' => 1]], parse('/GO/IHF26/Q/'));
check('trailing slash on a click', [$route, ['code' => 'ihf26', 'scan' => 0]], parse('/go/ihf26/'));
check('prefix alone is not ours', false, parse('/go'));
check('prefix with slash is not ours', false, parse('/go/'));
check('one-character code is not ours', false, parse('/go/a'));
check('unknown suffix is not ours', false, parse('/go/ihf26/x'));
check('underscore is not ours', false, parse('/go/ihf_26'));
check('pages are not ours', false, parse('/de/news'));
check('prefix must be a whole segment', false, parse('/gold/abc'));

echo "\nCustom prefix\n";
check('custom prefix matches', [$route, ['code' => 'abc', 'scan' => 0]], parse('/kampagne/abc', params: ['shortLinks' => ['prefix' => 'kampagne']]));
check('default prefix no longer matches', false, parse('/go/abc', params: ['shortLinks' => ['prefix' => 'kampagne']]));

echo "\nShort host\n";
$short = ['shortLinks' => ['shortHost' => 'fhb.link']];
check('code on the short host', [$route, ['code' => 'ihf26', 'scan' => 0]], parse('/ihf26', 'fhb.link', $short));
check('scan on the short host', [$route, ['code' => 'ihf26', 'scan' => 1]], parse('/ihf26/q', 'fhb.link', $short));
check('uppercase short host', [$route, ['code' => 'ihf26', 'scan' => 1]], parse('/IHF26/Q', 'FHB.LINK', $short));
check('short host root goes home', [$home, []], parse('/', 'fhb.link', $short));
check('other short host paths go home', [$home, []], parse('/some/deep/path', 'fhb.link', $short));
check('main host still uses the prefix', [$route, ['code' => 'ihf26', 'scan' => 0]], parse('/go/ihf26', 'forum-holzbau.test', $short));

echo "\nRegistration\n";
$catchAll = ['components' => ['urlManager' => ['rules' => ['<slug:.+>' => 'site/page']]]];

shortLinkApp([], ['REQUEST_URI' => '/go/ihf26'], $catchAll);
ShortLinkUrlRule::register(Yii::$app->urlManager);
check('registered rule wins over a catch-all rule', $route, Yii::$app->urlManager->parseRequest(Yii::$app->request)[0]);

shortLinkApp(['shortLinks' => ['enabled' => false]], ['REQUEST_URI' => '/go/ihf26'], $catchAll);
ShortLinkUrlRule::register(Yii::$app->urlManager);
check('disabled feature registers nothing', 'site/page', Yii::$app->urlManager->parseRequest(Yii::$app->request)[0]);

shortLinkDone();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/ShortLinkUrlRuleTest.php`
Expected: fatal error `Class "giantbits\crelish\components\shortlinks\ShortLinkUrlRule" not found`.

- [ ] **Step 3: Write the rule**

`components/shortlinks/ShortLinkUrlRule.php`:

```php
<?php

namespace giantbits\crelish\components\shortlinks;

use yii\base\BaseObject;
use yii\web\UrlManager;
use yii\web\UrlRuleInterface;

/**
 * Routes /<prefix>/<code>[/q] (and /<code>[/q] on a dedicated short host) to
 * the short link redirect. Matching is case-insensitive because QR codes carry
 * the URL in uppercase.
 *
 * Must run before CrelishBaseUrlRule, which would otherwise treat the prefix
 * as a page slug or language code.
 */
class ShortLinkUrlRule extends BaseObject implements UrlRuleInterface
{
  public const ROUTE = 'crelish/short-link-redirect/index';
  public const HOME_ROUTE = 'crelish/short-link-redirect/home';

  public static function register(UrlManager $manager): void
  {
    if (!ShortLinkConfig::isEnabled()) {
      return;
    }

    $manager->addRules([['class' => self::class]], false);
  }

  public function parseRequest($manager, $request)
  {
    $path = trim($request->getPathInfo(), '/');
    $shortHost = ShortLinkConfig::shortHost();

    if ($shortHost !== null && strtolower((string)$request->getHostName()) === $shortHost) {
      $params = $this->match($path);

      return $params !== null ? [self::ROUTE, $params] : [self::HOME_ROUTE, []];
    }

    $prefix = ShortLinkConfig::prefix();

    if (!str_starts_with(strtolower($path) . '/', $prefix . '/')) {
      return false;
    }

    $params = $this->match(substr($path, strlen($prefix) + 1));

    return $params !== null ? [self::ROUTE, $params] : false;
  }

  public function createUrl($manager, $route, $params)
  {
    return false;
  }

  /**
   * @return array{code: string, scan: int}|null
   */
  private function match(string $rest): ?array
  {
    if (!preg_match('~^([a-z0-9-]{2,64})(/q)?/?$~i', $rest, $matches)) {
      return null;
    }

    return ['code' => strtolower($matches[1]), 'scan' => empty($matches[2]) ? 0 : 1];
  }
}
```

- [ ] **Step 4: Register it in `Bootstrap`**

In `Bootstrap.php` add `use giantbits\crelish\components\shortlinks\ShortLinkUrlRule;` to the imports and change `configureUrlRules()` to:

```php
  private function configureUrlRules(WebApplication $app): void
  {
    $app->getUrlManager()->addRules(UrlRulesConfig::getRules(), true);

    // Prepended so it runs before CrelishBaseUrlRule and project rules
    ShortLinkUrlRule::register($app->getUrlManager());
  }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php tests/ShortLinkUrlRuleTest.php && php tests/ShortLinkCodeTest.php`
Expected: all `ok`, both exit 0.

- [ ] **Step 6: Commit**

```bash
git add components/shortlinks/ShortLinkUrlRule.php Bootstrap.php tests/ShortLinkUrlRuleTest.php
git commit -m "feat(shortlinks): route /go/<code> and short-host paths"
```

---

### Task 3: Target resolution

**Files:**
- Create: `components/shortlinks/ShortLinkTargetInterface.php`
- Create: `components/shortlinks/ResolveResult.php`
- Create: `components/shortlinks/ShortLinkResolver.php`
- Test: `tests/ShortLinkResolverTest.php`

**Interfaces:**
- Consumes: `ShortLink` (`isLive`, target fields), `ShortLinkConfig::absolute/siteFallbackUrl/detailPages`, `CrelishBaseHelper::urlFromSlug($slug, $params, $langCode)`
- Produces:
  - `interface ShortLinkTargetInterface { public function getShortLinkUrl(?string $language): ?string; }`
  - `ResolveResult` with readonly `string $url`, `string $reason` (`ResolveResult::REASON_OK|REASON_INACTIVE|REASON_BROKEN`) and `isFallback(): bool`
  - `new ShortLinkResolver(?callable $recordFinder = null)` where `$recordFinder(string $ctype, string $uuid): ?object`
  - `resolve(ShortLink $link, ?string $acceptLanguage = null, ?int $now = null): ResolveResult`
  - `resolveContent(ShortLink $link, ?string $acceptLanguage = null, ?int $now = null): ?string` (absolute URL or null)
  - `fallbackFor(ShortLink $link): string`
  - `static canResolveType(string $ctype): bool`, `static resolvableTypes(): string[]`, `static findRecord(string $ctype, string $uuid): ?object`

- [ ] **Step 1: Write the failing test**

`tests/ShortLinkResolverTest.php`:

```php
<?php

/**
 * Short link resolution: state and validity, external and content targets,
 * resolution order, record publication windows, language, fallback chain.
 *
 * Run with:  php tests/ShortLinkResolverTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\shortlinks\ResolveResult;
use giantbits\crelish\components\shortlinks\ShortLinkResolver;
use giantbits\crelish\components\shortlinks\ShortLinkTargetInterface;
use giantbits\crelish\models\ShortLink;

class EventTarget implements ShortLinkTargetInterface
{
    public string $uuid = 'e0000000-0000-4000-8000-000000000001';
    public int $state = 2;

    public function getShortLinkUrl(?string $language): ?string
    {
        return 'https://events.example.com/ihf?lang=' . $language;
    }
}

const NEWS_UUID = 'a0000000-0000-4000-8000-000000000001';
const PAGE_UUID = 'b0000000-0000-4000-8000-000000000001';

function records(array $overrides = []): array
{
    return array_merge([
        'news/' . NEWS_UUID => (object)['uuid' => NEWS_UUID, 'state' => 2, 'systitle' => 'Holzbau Forum 2026: Programm', 'from' => null, 'to' => null],
        'page/' . PAGE_UUID => (object)['uuid' => PAGE_UUID, 'state' => 2, 'systitle' => 'Programm', 'slug' => 'programm'],
        'event/' . (new EventTarget())->uuid => new EventTarget(),
        'sponsor/s1' => (object)['uuid' => 's1', 'state' => 2, 'systitle' => 'Sponsor'],
    ], $overrides);
}

function resolver(array $records): ShortLinkResolver
{
    return new ShortLinkResolver(fn(string $ctype, string $uuid) => $records["$ctype/$uuid"] ?? null);
}

function urlLink(array $attributes = []): ShortLink
{
    $link = new ShortLink();
    $link->setAttributes(array_merge([
        'systitle' => 'Flyer', 'code' => 'ihf26', 'state' => 2,
        'target_type' => ShortLink::TARGET_URL, 'target_url' => 'https://www.example.com/ihf',
    ], $attributes), false);

    return $link;
}

function contentLink(string $ctype, string $uuid, array $attributes = []): ShortLink
{
    return urlLink(array_merge(['target_type' => ShortLink::TARGET_CONTENT, 'target_url' => null, 'target_ctype' => $ctype, 'target_uuid' => $uuid], $attributes));
}

$now = strtotime('2026-09-24 12:00');
$site = 'https://forum-holzbau.test';

shortLinkApp(['languages' => ['de', 'en'], 'shortLinks' => ['detailPages' => ['news' => 'news', 'event' => 'events']]]);
$resolve = resolver(records());

echo "External targets and link state\n";
$result = $resolve->resolve(urlLink(), null, $now);
check('online url target', 'https://www.example.com/ihf', $result->url);
check('online url target is not a fallback', false, $result->isFallback());

$result = $resolve->resolve(urlLink(['state' => 0, 'fallback_url' => 'https://www.example.com/archiv']), null, $now);
check('offline link uses its fallback', 'https://www.example.com/archiv', $result->url);
check('offline link reason', ResolveResult::REASON_INACTIVE, $result->reason);
check('offline link without fallback goes home', "$site/de", $resolve->resolve(urlLink(['state' => 1]), null, $now)->url);
check('scheduled link falls back', ResolveResult::REASON_INACTIVE, $resolve->resolve(urlLink(['valid_from' => $now + 60]), null, $now)->reason);
check('expired link falls back', ResolveResult::REASON_INACTIVE, $resolve->resolve(urlLink(['valid_until' => $now - 60]), null, $now)->reason);

echo "\nContent targets\n";
check('page resolves by slug', "$site/de/programm", $resolve->resolve(contentLink('page', PAGE_UUID), null, $now)->url);
check('news resolves through detailPages', "$site/de/news/" . NEWS_UUID . '/holzbau-forum-2026-programm', $resolve->resolve(contentLink('news', NEWS_UUID), null, $now)->url);
check('interface wins over detailPages', 'https://events.example.com/ihf?lang=de', $resolve->resolve(contentLink('event', (new EventTarget())->uuid), null, $now)->url);

$result = $resolve->resolve(contentLink('news', 'missing'), null, $now);
check('missing record is broken', ResolveResult::REASON_BROKEN, $result->reason);
check('missing record goes to the site fallback', "$site/de", $result->url);
check('type without mapping or slug is broken', ResolveResult::REASON_BROKEN, $resolve->resolve(contentLink('sponsor', 's1'), null, $now)->reason);

$draft = resolver(records(['news/' . NEWS_UUID => (object)['uuid' => NEWS_UUID, 'state' => 1, 'systitle' => 'X']]));
check('unpublished record is broken', ResolveResult::REASON_BROKEN, $draft->resolve(contentLink('news', NEWS_UUID), null, $now)->reason);

echo "\nRecord publication window\n";
$ending = resolver(records(['news/' . NEWS_UUID => (object)['uuid' => NEWS_UUID, 'state' => 2, 'systitle' => 'X', 'from' => null, 'to' => '2026-09-24']]));
check('date-only "to" is valid through the end of that day', ResolveResult::REASON_OK, $ending->resolve(contentLink('news', NEWS_UUID), null, strtotime('2026-09-24 18:00'))->reason);
check('date-only "to" has ended the next day', ResolveResult::REASON_BROKEN, $ending->resolve(contentLink('news', NEWS_UUID), null, strtotime('2026-09-25 00:01'))->reason);
$starting = resolver(records(['news/' . NEWS_UUID => (object)['uuid' => NEWS_UUID, 'state' => 2, 'systitle' => 'X', 'from' => '2026-10-01', 'to' => null]]));
check('"from" in the future is broken', ResolveResult::REASON_BROKEN, $starting->resolve(contentLink('news', NEWS_UUID), null, $now)->reason);
$stamps = resolver(records(['news/' . NEWS_UUID => (object)['uuid' => NEWS_UUID, 'state' => 2, 'systitle' => 'X', 'from' => (string)($now - 10), 'to' => (string)($now + 10)]]));
check('unix timestamps are understood', ResolveResult::REASON_OK, $stamps->resolve(contentLink('news', NEWS_UUID), null, $now)->reason);
$zero = resolver(records(['news/' . NEWS_UUID => (object)['uuid' => NEWS_UUID, 'state' => 2, 'systitle' => 'X', 'from' => '0000-00-00', 'to' => '0000-00-00 00:00:00']]));
check('zero dates mean no window', ResolveResult::REASON_OK, $zero->resolve(contentLink('news', NEWS_UUID), null, $now)->reason);

echo "\nLanguage\n";
check('target_language wins', "$site/en/programm", $resolve->resolve(contentLink('page', PAGE_UUID, ['target_language' => 'en']), 'de-DE', $now)->url);
check('Accept-Language picks a supported language', "$site/en/programm", $resolve->resolve(contentLink('page', PAGE_UUID), 'en-GB,en;q=0.9,de;q=0.8', $now)->url);
check('Accept-Language respects q values', "$site/de/programm", $resolve->resolve(contentLink('page', PAGE_UUID), 'en;q=0.4,de;q=0.9', $now)->url);
check('unsupported Accept-Language uses the default', "$site/de/programm", $resolve->resolve(contentLink('page', PAGE_UUID), 'fr-FR,fr;q=0.9', $now)->url);
check('malformed Accept-Language uses the default', "$site/de/programm", $resolve->resolve(contentLink('page', PAGE_UUID), ';;;q=,', $now)->url);

echo "\nFallback chain\n";
shortLinkApp(['shortLinks' => ['fallbackUrl' => '/de/archiv']]);
check('configured site fallback is made absolute', "$site/de/archiv", resolver(records())->resolve(urlLink(['state' => 0]), null, $now)->url);

echo "\nResolvable types\n";
shortLinkApp(['shortLinks' => ['detailPages' => ['news' => 'news']]]);
check('page is resolvable', true, ShortLinkResolver::canResolveType('page'));
check('mapped type is resolvable', true, ShortLinkResolver::canResolveType('news'));
check('unmapped type without model is not', false, ShortLinkResolver::canResolveType('sponsor'));

shortLinkDone();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/ShortLinkResolverTest.php`
Expected: fatal error, class `ShortLinkTargetInterface` not found.

- [ ] **Step 3: Write the interface and result**

`components/shortlinks/ShortLinkTargetInterface.php`:

```php
<?php

namespace giantbits\crelish\components\shortlinks;

/**
 * Implement on a content model whose detail URL does not follow the
 * urlFromSlug('<listing>')/<uuid>/<slug> convention.
 */
interface ShortLinkTargetInterface
{
  /**
   * @param string|null $language two-letter language code
   * @return string|null absolute or site-relative URL, null when not reachable
   */
  public function getShortLinkUrl(?string $language): ?string;
}
```

`components/shortlinks/ResolveResult.php`:

```php
<?php

namespace giantbits\crelish\components\shortlinks;

/**
 * Where a short link hit goes, and why.
 */
final class ResolveResult
{
  public const REASON_OK = 'ok';
  public const REASON_INACTIVE = 'inactive';
  public const REASON_BROKEN = 'broken';

  public function __construct(
    public readonly string $url,
    public readonly string $reason,
  ) {
  }

  public function isFallback(): bool
  {
    return $this->reason !== self::REASON_OK;
  }
}
```

- [ ] **Step 4: Write the resolver**

`components/shortlinks/ShortLinkResolver.php`:

```php
<?php

namespace giantbits\crelish\components\shortlinks;

use Cocur\Slugify\Slugify;
use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\CrelishModelResolver;
use giantbits\crelish\models\ShortLink;
use Yii;
use yii\db\BaseActiveRecord;

/**
 * Decides where a short link sends a visitor right now.
 *
 * Content targets are resolved at redirect time, so links survive slug and
 * title changes. Resolution order: ShortLinkTargetInterface, detailPages
 * config, then a page-style slug attribute.
 */
class ShortLinkResolver
{
  /** @var callable(string, string): ?object */
  private $recordFinder;

  public function __construct(?callable $recordFinder = null)
  {
    $this->recordFinder = $recordFinder ?? [self::class, 'findRecord'];
  }

  public static function findRecord(string $ctype, string $uuid): ?object
  {
    if (!CrelishModelResolver::modelExists($ctype)) {
      return null;
    }

    $class = CrelishModelResolver::getModelClass($ctype);

    return $class::find()->where(['uuid' => $uuid])->one();
  }

  public function resolve(ShortLink $link, ?string $acceptLanguage = null, ?int $now = null): ResolveResult
  {
    if (!$link->isLive($now)) {
      return new ResolveResult($this->fallbackFor($link), ResolveResult::REASON_INACTIVE);
    }

    if ($link->target_type === ShortLink::TARGET_URL) {
      return new ResolveResult((string)$link->target_url, ResolveResult::REASON_OK);
    }

    $url = $this->resolveContent($link, $acceptLanguage, $now);

    return $url !== null
      ? new ResolveResult($url, ResolveResult::REASON_OK)
      : new ResolveResult($this->fallbackFor($link), ResolveResult::REASON_BROKEN);
  }

  /**
   * @return string|null absolute URL of the content target, null when it cannot be reached
   */
  public function resolveContent(ShortLink $link, ?string $acceptLanguage = null, ?int $now = null): ?string
  {
    if (empty($link->target_ctype) || empty($link->target_uuid)) {
      return null;
    }

    $record = ($this->recordFinder)($link->target_ctype, $link->target_uuid);

    if ($record === null || !self::isPublished($record, $now ?? time())) {
      return null;
    }

    $language = $this->language($link, $acceptLanguage);

    if ($record instanceof ShortLinkTargetInterface) {
      $url = $record->getShortLinkUrl($language);
      return $url ? ShortLinkConfig::absolute($url) : null;
    }

    $detailPage = ShortLinkConfig::detailPages()[$link->target_ctype] ?? null;

    if ($detailPage !== null) {
      $slug = Slugify::create()->slugify((string)self::attribute($record, 'systitle'));

      return ShortLinkConfig::absolute(
        CrelishBaseHelper::urlFromSlug($detailPage, [], $language) . '/' . self::attribute($record, 'uuid') . '/' . $slug
      );
    }

    $slug = self::attribute($record, 'slug');

    return $slug ? ShortLinkConfig::absolute(CrelishBaseHelper::urlFromSlug((string)$slug, [], $language)) : null;
  }

  public function fallbackFor(ShortLink $link): string
  {
    return $link->fallback_url ?: ShortLinkConfig::siteFallbackUrl();
  }

  public static function canResolveType(string $ctype): bool
  {
    if ($ctype === 'page' || isset(ShortLinkConfig::detailPages()[$ctype])) {
      return true;
    }

    try {
      $class = CrelishModelResolver::getModelClass($ctype);
    } catch (\Throwable) {
      return false;
    }

    return is_subclass_of($class, ShortLinkTargetInterface::class);
  }

  /**
   * @return string[] content types an editor can pick as a target
   */
  public static function resolvableTypes(): array
  {
    $types = array_merge(['page'], array_keys(ShortLinkConfig::detailPages()));

    try {
      foreach (CrelishModelResolver::getAllModels() as $ctype => $class) {
        if (is_subclass_of($class, ShortLinkTargetInterface::class)) {
          $types[] = $ctype;
        }
      }
    } catch (\Throwable $e) {
      Yii::warning('Short links: model discovery failed: ' . $e->getMessage(), 'shortlink');
    }

    $types = array_values(array_unique($types));
    sort($types);

    return $types;
  }

  private function language(ShortLink $link, ?string $acceptLanguage): string
  {
    if (!empty($link->target_language)) {
      return (string)$link->target_language;
    }

    $supported = Yii::$app->params['crelish']['languages'] ?? [];

    foreach (self::acceptedLanguages($acceptLanguage) as $code) {
      if (in_array($code, $supported, true)) {
        return $code;
      }
    }

    return substr(Yii::$app->language, 0, 2);
  }

  /**
   * @return string[] two-letter codes from an Accept-Language header, best first
   */
  private static function acceptedLanguages(?string $header): array
  {
    $weighted = [];

    foreach (explode(',', (string)$header) as $index => $part) {
      $pieces = explode(';', trim($part));
      $code = strtolower(substr(trim($pieces[0]), 0, 2));

      if (!preg_match('/^[a-z]{2}$/', $code)) {
        continue;
      }

      $quality = 1.0;
      foreach (array_slice($pieces, 1) as $parameter) {
        if (preg_match('/^\s*q=([0-9.]+)\s*$/', $parameter, $matches)) {
          $quality = (float)$matches[1];
        }
      }

      $weighted[] = [$code, $quality, $index];
    }

    usort($weighted, static fn(array $a, array $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

    return array_values(array_unique(array_column($weighted, 0)));
  }

  private static function isPublished(object $record, int $now): bool
  {
    $state = self::attribute($record, 'state');

    if ($state !== null && (int)$state !== ShortLink::STATE_ONLINE) {
      return false;
    }

    $from = self::timestamp(self::attribute($record, 'from'), false);
    $to = self::timestamp(self::attribute($record, 'to'), true);

    return ($from === null || $from <= $now) && ($to === null || $to >= $now);
  }

  private static function timestamp(mixed $value, bool $endOfDay): ?int
  {
    if ($value === null || $value === '' || $value === 0 || $value === '0') {
      return null;
    }

    if (is_numeric($value)) {
      return (int)$value;
    }

    $value = (string)$value;

    if (str_starts_with($value, '0000-00-00')) {
      return null;
    }

    if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      $value .= ' 23:59:59';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? null : $timestamp;
  }

  private static function attribute(object $record, string $name): mixed
  {
    if ($record instanceof BaseActiveRecord) {
      return $record->hasAttribute($name) ? $record->getAttribute($name) : null;
    }

    return $record->$name ?? null;
  }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/ShortLinkResolverTest.php`
Expected: all `ok`, exit 0.

- [ ] **Step 6: Commit**

```bash
git add components/shortlinks/ShortLinkTargetInterface.php components/shortlinks/ResolveResult.php components/shortlinks/ShortLinkResolver.php tests/ShortLinkResolverTest.php
git commit -m "feat(shortlinks): resolve targets at redirect time with fallback chain"
```

---

### Task 4: Analytics `trackEvent`

**Files:**
- Modify: `components/CrelishAnalyticsComponent.php` (add a public method after `trackElementView()`)
- Test: `tests/ShortLinkTrackingTest.php`

**Interfaces:**
- Produces: `CrelishAnalyticsComponent::trackEvent(string $elementUuid, string $elementType, string $type): bool`. It writes one `analytics_element_views` row with `page_uuid = $elementUuid` and makes sure an `analytics_sessions` row exists.

- [ ] **Step 1: Write the failing test**

`tests/ShortLinkTrackingTest.php`:

```php
<?php

/**
 * trackEvent: element events that do not come from a page view still need a
 * session row, or the nightly job deletes them as orphans.
 *
 * Run with:  php tests/ShortLinkTrackingTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use yii\db\Query;

const LINK = 'c0000000-0000-4000-8000-000000000001';

function sessions(): array
{
    return (new Query())->from('analytics_sessions')->all();
}

function views(): array
{
    return (new Query())->from('analytics_element_views')->orderBy('id')->all();
}

echo "New session\n";
shortLinkApp([], ['REQUEST_URI' => '/go/ihf26/q']);
check('trackEvent reports success', true, Yii::$app->crelishAnalytics->trackEvent(LINK, 'shortlink', 'scan'));
$session = sessions()[0] ?? [];
check('a session row is created', 1, count(sessions()));
check('session starts at the short link', LINK, $session['first_page_uuid'] ?? null);
check('session first_url is the short URL', 'https://forum-holzbau.test/go/ihf26/q', $session['first_url'] ?? null);
check('a redirect is not a page', 0, (int)($session['total_pages'] ?? -1));
check('iPhone Safari is not a bot', 0, (int)($session['is_bot'] ?? -1));
$view = views()[0] ?? [];
check('element type', 'shortlink', $view['element_type'] ?? null);
check('event type', 'scan', $view['type'] ?? null);
check('page_uuid is the link itself (NOT NULL column)', LINK, $view['page_uuid'] ?? null);
check('event belongs to the session', $session['session_id'] ?? 'x', $view['session_id'] ?? 'y');

echo "\nSame session again\n";
Yii::$app->crelishAnalytics->trackEvent(LINK, 'shortlink', 'click');
check('no second session row', 1, count(sessions()));
check('two events recorded', 2, count(views()));
check('total_pages still untouched', 0, (int)sessions()[0]['total_pages']);

echo "\nExisting page-view session, then a bot request\n";
shortLinkApp([], ['HTTP_USER_AGENT' => 'curl/8.4.0']);
$sessionId = Yii::$app->crelishAnalytics->getSessionId();
Yii::$app->db->createCommand()->insert('analytics_sessions', ['session_id' => $sessionId, 'is_bot' => 0, 'total_pages' => 3])->execute();
Yii::$app->crelishAnalytics->trackEvent(LINK, 'shortlink', 'click');
check('bot flag is upgraded', 1, (int)sessions()[0]['is_bot']);
check('page count is preserved', 3, (int)sessions()[0]['total_pages']);

echo "\nExclusions\n";
shortLinkApp([], [], ['components' => ['crelishAnalytics' => ['excludeIps' => ['203.0.113.7']]]]);
check('excluded IP is not tracked', false, Yii::$app->crelishAnalytics->trackEvent(LINK, 'shortlink', 'scan'));
check('excluded IP writes nothing', [0, 0], [count(sessions()), count(views())]);

shortLinkApp([], [], ['components' => ['crelishAnalytics' => ['enabled' => false]]]);
check('disabled analytics is not tracked', false, Yii::$app->crelishAnalytics->trackEvent(LINK, 'shortlink', 'scan'));

echo "\nOversized values\n";
$longAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' . str_repeat(' Extension/1.0', 30);
shortLinkApp([], ['HTTP_USER_AGENT' => $longAgent, 'REQUEST_URI' => '/go/ihf26?' . str_repeat('x', 400)]);
check('long values are still tracked', true, Yii::$app->crelishAnalytics->trackEvent(LINK, 'shortlink', 'click'));
check('user_agent is cut to 255', 255, mb_strlen((string)sessions()[0]['user_agent']));
check('first_url is cut to 255', 255, mb_strlen((string)sessions()[0]['first_url']));

shortLinkDone();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/ShortLinkTrackingTest.php`
Expected: fatal error `Call to undefined method ...CrelishAnalyticsComponent::trackEvent()`.

- [ ] **Step 3: Implement `trackEvent`**

In `components/CrelishAnalyticsComponent.php`, directly after the closing brace of `trackElementView()`, add:

```php
  /**
   * Track an element event that is not tied to a page view (e.g. a short link redirect)
   *
   * Element events are only kept by the nightly aggregation when their session
   * exists, and a visitor who scans a QR code has no page view yet. So this
   * creates the session row itself, without counting a page.
   *
   * @param string $elementUuid
   * @param string $elementType
   * @param string $type event type, e.g. 'scan', 'click', 'fallback'
   * @return bool
   */
  public function trackEvent(string $elementUuid, string $elementType, string $type): bool
  {
    if (!$this->enabled || in_array(Yii::$app->request->userIP, $this->excludeIps)) {
      return false;
    }

    $db = Yii::$app->db;
    $isBot = $this->isBot();
    $userId = !Yii::$app->user->isGuest ? Yii::$app->user->id : null;

    $session = (new Query())
      ->select(['is_bot'])
      ->from('analytics_sessions')
      ->where(['session_id' => $this->_sessionId])
      ->one();

    if ($session === false) {
      $db->createCommand()->insert('analytics_sessions', [
        'session_id' => $this->_sessionId,
        'user_id' => $userId,
        'ip_address' => mb_substr((string)Yii::$app->request->userIP, 0, 45),
        'user_agent' => mb_substr((string)Yii::$app->request->userAgent, 0, 255),
        'is_bot' => $isBot ? 1 : 0,
        'first_page_uuid' => $elementUuid,
        'first_url' => mb_substr(Yii::$app->request->absoluteUrl, 0, 255),
        'total_pages' => 0,
      ])->execute();
    } elseif ($isBot && !$session['is_bot']) {
      $db->createCommand()->update('analytics_sessions', ['is_bot' => 1], ['session_id' => $this->_sessionId])->execute();
    }

    return (bool)$db->createCommand()->insert('analytics_element_views', [
      'element_uuid' => $elementUuid,
      'element_type' => $elementType,
      // page_uuid is NOT NULL; the event itself is the "page" here
      'page_uuid' => $elementUuid,
      'session_id' => $this->_sessionId,
      'user_id' => $userId,
      'created_at' => new Expression('NOW()'),
      'type' => $type,
    ])->execute();
  }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/ShortLinkTrackingTest.php`
Expected: all `ok`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add components/CrelishAnalyticsComponent.php tests/ShortLinkTrackingTest.php
git commit -m "feat(analytics): trackEvent for element events without a page view"
```

---

### Task 5: Public redirect controller

**Files:**
- Create: `controllers/ShortLinkRedirectController.php`
- Test: `tests/ShortLinkRedirectTest.php`

**Interfaces:**
- Consumes: `ShortLink::findByCode`, `ShortLinkResolver::resolve`, `ResolveResult`, `ShortLinkConfig::siteFallbackUrl/homeUrl/absolute/isEnabled`, `CrelishAnalyticsComponent::trackEvent`, route params from Task 2 (`code`, `scan`)
- Produces: routes `crelish/short-link-redirect/index` and `crelish/short-link-redirect/home`

- [ ] **Step 1: Write the failing test**

`tests/ShortLinkRedirectTest.php`:

```php
<?php

/**
 * Short link redirect: destination, headers, tracking, no open redirect,
 * and graceful degradation when tracking or the table fails.
 *
 * Run with:  php tests/ShortLinkRedirectTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\controllers\ShortLinkRedirectController;
use giantbits\crelish\models\ShortLink;
use yii\db\Query;
use yii\log\Logger;
use yii\web\NotFoundHttpException;

function seed(array $attributes = []): ShortLink
{
    $link = new ShortLink();
    $link->setAttributes(array_merge([
        'systitle' => 'Flyer', 'code' => 'ihf26', 'state' => 2,
        'target_type' => ShortLink::TARGET_URL, 'target_url' => 'https://www.example.com/ihf',
    ], $attributes), false);
    $link->save(false);

    return $link;
}

function hit(string $code, int $scan = 0, string $action = 'index'): \yii\web\Response
{
    Yii::$app->response->clear();
    $controller = new ShortLinkRedirectController('short-link-redirect', Yii::$app);

    return $controller->runAction($action, $action === 'index' ? ['code' => $code, 'scan' => $scan] : []);
}

function eventTypes(): array
{
    return (new Query())->select('type')->from('analytics_element_views')->orderBy('id')->column();
}

function logCount(string $category, int $level): int
{
    return count(array_filter(Yii::getLogger()->messages, fn($m) => $m[2] === $category && $m[1] === $level));
}

echo "Redirects\n";
shortLinkApp();
seed();
$response = hit('ihf26');
check('status is 302', 302, $response->statusCode);
check('goes to the target', 'https://www.example.com/ihf', $response->headers->get('location'));
check('not cacheable', 'no-store', $response->headers->get('cache-control'));
check('not indexable', 'noindex', $response->headers->get('x-robots-tag'));
check('click is tracked', ['click'], eventTypes());

hit('IHF26', 1);
check('uppercase code works and scan is tracked', ['click', 'scan'], eventTypes());

echo "\nNo open redirect\n";
$_GET = ['redirect' => 'https://evil.example/', 'url' => 'https://evil.example/', 'target' => 'https://evil.example/'];
check('query parameters cannot change the destination', 'https://www.example.com/ihf', hit('ihf26')->headers->get('location'));
$_GET = [];

echo "\nUnknown and inactive links\n";
$before = count(eventTypes());
check('unknown code goes home', 'https://forum-holzbau.test/de', hit('nope99')->headers->get('location'));
check('unknown code is not tracked', $before, count(eventTypes()));

seed(['code' => 'off001', 'state' => 0, 'fallback_url' => 'https://www.example.com/archiv']);
check('offline link goes to its fallback', 'https://www.example.com/archiv', hit('off001', 1)->headers->get('location'));
check('fallback hit is tracked as fallback', 'fallback', array_slice(eventTypes(), -1)[0]);

echo "\nBroken content target\n";
seed(['code' => 'brk001', 'target_type' => ShortLink::TARGET_CONTENT, 'target_url' => null, 'target_ctype' => 'news', 'target_uuid' => 'a0000000-0000-4000-8000-000000000009']);
$warnings = logCount('shortlink', Logger::LEVEL_WARNING);
check('broken target goes home', 'https://forum-holzbau.test/de', hit('brk001')->headers->get('location'));
hit('brk001');
check('broken target warns once per day', $warnings + 1, logCount('shortlink', Logger::LEVEL_WARNING));

echo "\nFailures degrade gracefully\n";
shortLinkApp();
seed();
Yii::$app->db->createCommand()->dropTable('analytics_element_views')->execute();
$errors = logCount('analytics', Logger::LEVEL_ERROR);
check('tracking failure still redirects', 'https://www.example.com/ihf', hit('ihf26')->headers->get('location'));
check('tracking failure is logged', $errors + 1, logCount('analytics', Logger::LEVEL_ERROR));

shortLinkApp();
Yii::$app->db->createCommand()->dropTable('shortlink')->execute();
check('missing table goes home', 'https://forum-holzbau.test/de', hit('ihf26')->headers->get('location'));

echo "\nShort host home and disabled feature\n";
shortLinkApp();
check('home action goes to the main site', 'https://forum-holzbau.test/de', hit('', 0, 'home')->headers->get('location'));

shortLinkApp(['shortLinks' => ['enabled' => false]]);
try {
    hit('ihf26');
    $notFound = false;
} catch (NotFoundHttpException) {
    $notFound = true;
}
check('disabled feature is a 404', true, $notFound);

shortLinkDone();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/ShortLinkRedirectTest.php`
Expected: fatal error, class `ShortLinkRedirectController` not found.

- [ ] **Step 3: Write the controller**

`controllers/ShortLinkRedirectController.php`:

```php
<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\shortlinks\ResolveResult;
use giantbits\crelish\components\shortlinks\ShortLinkConfig;
use giantbits\crelish\components\shortlinks\ShortLinkResolver;
use giantbits\crelish\models\ShortLink;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Public short link redirect.
 *
 * Deliberately a plain controller: CrelishBaseController redirects anyone who
 * is not an admin. Bots are redirected too; they are flagged through the
 * analytics session and filtered by the nightly aggregation.
 */
class ShortLinkRedirectController extends Controller
{
  public $enableCsrfValidation = false;

  public function beforeAction($action)
  {
    if (!ShortLinkConfig::isEnabled()) {
      throw new NotFoundHttpException();
    }

    return parent::beforeAction($action);
  }

  public function actionIndex(string $code, int $scan = 0): Response
  {
    try {
      $link = ShortLink::findByCode($code);
    } catch (\Throwable $e) {
      Yii::error("Short link lookup failed for '{$code}': " . $e->getMessage(), 'shortlink');
      return $this->send(ShortLinkConfig::siteFallbackUrl());
    }

    if ($link === null) {
      Yii::info("Unknown short link code '{$code}'", 'shortlink');
      return $this->send(ShortLinkConfig::siteFallbackUrl());
    }

    $result = (new ShortLinkResolver())->resolve($link, Yii::$app->request->headers->get('Accept-Language'));

    if ($result->reason === ResolveResult::REASON_BROKEN) {
      $this->warnBroken($link);
    }

    $this->track($link, $result->isFallback() ? 'fallback' : ($scan ? 'scan' : 'click'));

    return $this->send($result->url);
  }

  /**
   * Any other path on a dedicated short host
   */
  public function actionHome(): Response
  {
    return $this->send(ShortLinkConfig::homeUrl());
  }

  private function track(ShortLink $link, string $type): void
  {
    try {
      if (Yii::$app->has('crelishAnalytics')) {
        Yii::$app->get('crelishAnalytics')->trackEvent($link->uuid, 'shortlink', $type);
      }
    } catch (\Throwable $e) {
      Yii::error("Short link tracking failed for '{$link->code}': " . $e->getMessage(), 'analytics');
    }
  }

  /**
   * Warn once per link and day; a broken link on a flyer would otherwise flood Sentry
   */
  private function warnBroken(ShortLink $link): void
  {
    $cache = Yii::$app->getCache();
    $key = ['shortlink-broken', $link->uuid, date('Y-m-d')];

    if ($cache !== null) {
      if ($cache->get($key)) {
        return;
      }
      $cache->set($key, 1, 86400);
    }

    Yii::warning("Short link '{$link->code}' ({$link->uuid}) points to {$link->target_ctype}/{$link->target_uuid}, which cannot be resolved; sent to the fallback", 'shortlink');
  }

  private function send(string $url): Response
  {
    $response = $this->redirect(ShortLinkConfig::absolute($url), 302);
    $response->headers->set('Cache-Control', 'no-store');
    $response->headers->set('X-Robots-Tag', 'noindex');

    return $response;
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/ShortLinkRedirectTest.php && php tests/ShortLinkResolverTest.php && php tests/ShortLinkTrackingTest.php`
Expected: all `ok`, all exit 0.

- [ ] **Step 5: Commit**

```bash
git add controllers/ShortLinkRedirectController.php tests/ShortLinkRedirectTest.php
git commit -m "feat(shortlinks): public redirect with tracking and safe fallbacks"
```

---

### Task 6: QR rendering

**Files:**
- Modify: `composer.json` (via `composer require`)
- Create: `components/shortlinks/qr/QrMatrix.php`
- Create: `components/shortlinks/qr/QrLogo.php`
- Create: `components/shortlinks/qr/QrPdf.php`
- Create: `components/shortlinks/qr/QrRenderer.php`
- Test: `tests/QrRenderTest.php`

**Interfaces:**
- Produces:
  - `QrMatrix::encode(string $data, string $errorCorrection /* 'M'|'H' */): QrMatrix`; `readonly int $size`; `isDark(int $x, int $y): bool`
  - `QrLogo::fromFile(string $path): QrLogo` (throws `RuntimeException`); readonly `string $file`, `string $png`, `int $width`, `int $height`
  - `new QrRenderer(QrMatrix $matrix, float $sizeMm, int $quietZone, string $color, ?QrLogo $logo = null)`; `svg(): string`, `eps(): string` (throws `LogicException` with a logo), `pdf(): string`, `png(): string`, `darkRuns(): array<array{0:int,1:int,2:int}>`, `knockout(): ?array{0:int,1:int,2:int,3:int}`; constants `PNG_MIN_DPI = 300`, `LOGO_SHARE = 0.22`

- [ ] **Step 1: Add dependencies**

Run from the crelish root:

```bash
composer require "bacon/bacon-qr-code:^3.0" "setasign/fpdf:^1.8" "ext-gd:*" "ext-zip:*"
```

Expected: `composer.json` gains the four entries; `vendor/bacon/bacon-qr-code` and `vendor/setasign/fpdf` exist.

- [ ] **Step 2: Write the failing test**

`tests/QrRenderTest.php`:

```php
<?php

/**
 * QR rendering: matrix size, exact physical size in all formats, logo
 * knockout, transparent logos in PDF.
 *
 * Run with:  php tests/QrRenderTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\shortlinks\qr\QrLogo;
use giantbits\crelish\components\shortlinks\qr\QrMatrix;
use giantbits\crelish\components\shortlinks\qr\QrRenderer;

const PAYLOAD = 'HTTPS://FORUM-HOLZBAU.COM/GO/IHF26/Q';

/**
 * A 400x200 transparent PNG with an opaque green rectangle
 */
function transparentLogo(): string
{
    $image = imagecreatetruecolor(400, 200);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 50, 50, 350, 150, imagecolorallocate($image, 47, 111, 79));
    $path = tempnam(sys_get_temp_dir(), 'qr-test-logo-') . '.png';
    imagepng($image, $path);

    return $path;
}

function pngDpi(string $png): ?int
{
    $position = strpos($png, 'pHYs');
    if ($position === false) {
        return null;
    }

    return (int)round(unpack('N', substr($png, $position + 4, 4))[1] * 0.0254);
}

echo "Matrix\n";
check('uppercase payload uses a version 2 code', 25, QrMatrix::encode(PAYLOAD, 'M')->size);
check('lowercase payload needs a bigger code', 29, QrMatrix::encode(strtolower(PAYLOAD), 'M')->size);
check('level H is larger', 33, QrMatrix::encode(PAYLOAD, 'H')->size);
$matrix = QrMatrix::encode(PAYLOAD, 'M');
check('finder pattern corner is dark', true, $matrix->isDark(0, 0));
check('finder separator is light', false, $matrix->isDark(7, 0));

echo "\nSVG\n";
$plain = new QrRenderer($matrix, 30, 4, '#1a5d3a');
$svg = simplexml_load_string($plain->svg());
check('svg width is in mm', '30mm', (string)$svg['width']);
check('svg viewBox covers code and quiet zone', '0 0 33 33', (string)$svg['viewBox']);
check('svg uses the colour', '#1a5d3a', (string)$svg->path['fill']);
check('svg has a white background', '#ffffff', (string)$svg->rect['fill']);

echo "\nEPS\n";
$eps = $plain->eps();
check('eps header', true, str_starts_with($eps, "%!PS-Adobe-3.0 EPSF-3.0\n"));
check('eps bounding box in points', true, str_contains($eps, "%%BoundingBox: 0 0 86 86\n"));
check('eps hi-res bounding box', true, str_contains($eps, "%%HiResBoundingBox: 0 0 85.0394 85.0394\n"));
check('eps ends with EOF', true, str_ends_with($eps, "%%EOF\n"));

echo "\nPDF\n";
$pdf = $plain->pdf();
check('pdf header', true, str_starts_with($pdf, '%PDF-'));
check('pdf page is 30 mm', true, str_contains($pdf, '/MediaBox [0 0 85.04 85.04]'));

echo "\nPNG\n";
$png = $plain->png();
$info = getimagesizefromstring($png);
check('png is whole pixels per module at >= 300 dpi', [363, 363], [$info[0], $info[1]]);
check('png carries its dpi', 307, pngDpi($png));

echo "\nLogo\n";
$logo = QrLogo::fromFile(transparentLogo());
$withLogo = new QrRenderer(QrMatrix::encode(PAYLOAD, 'H'), 30, 4, '#000000', $logo);
[$kx, $ky, $kw, $kh] = $withLogo->knockout();
check('knockout width is about 22 % of the code', 7, $kw);
check('wide logo gets a flat knockout', true, $kh < $kw);
check('knockout is centred on module 16 of 33', [16, 16], [$kx + intdiv($kw, 2), $ky + intdiv($kh, 2)]);
$overlaps = array_filter($withLogo->darkRuns(), fn($run) => $run[1] >= $ky && $run[1] < $ky + $kh && $run[0] < $kx + $kw && $run[0] + $run[2] > $kx);
check('no dark module inside the knockout', [], array_values($overlaps));
check('svg embeds the logo', true, str_contains($withLogo->svg(), 'data:image/png;base64,'));
try {
    $logoPdf = $withLogo->pdf();
} catch (\Throwable $e) {
    $logoPdf = 'failed: ' . $e->getMessage();
}
check('transparent logo works in pdf', true, str_contains($logoPdf, '/Subtype /Image'));
check('logo png renders', true, getimagesizefromstring($withLogo->png()) !== false);
try {
    $withLogo->eps();
    $epsThrew = false;
} catch (LogicException) {
    $epsThrew = true;
}
check('eps refuses a logo', true, $epsThrew);

echo "\nLogo errors\n";
foreach (['missing file' => '/nonexistent/logo.png', 'text file' => __FILE__] as $label => $path) {
    try {
        QrLogo::fromFile($path);
        $threw = false;
    } catch (RuntimeException) {
        $threw = true;
    }
    check("$label is rejected", true, $threw);
}

shortLinkDone();
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php tests/QrRenderTest.php`
Expected: fatal error, class `QrMatrix` not found.

- [ ] **Step 4: Write `QrMatrix`**

`components/shortlinks/qr/QrMatrix.php`:

```php
<?php

namespace giantbits\crelish\components\shortlinks\qr;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use InvalidArgumentException;

/**
 * The module grid of a QR code, without quiet zone.
 */
final class QrMatrix
{
  /**
   * @param bool[][] $rows [y][x] => dark
   */
  private function __construct(
    public readonly int $size,
    private readonly array $rows,
  ) {
  }

  public static function encode(string $data, string $errorCorrection): self
  {
    $level = match ($errorCorrection) {
      'M' => ErrorCorrectionLevel::M(),
      'H' => ErrorCorrectionLevel::H(),
      default => throw new InvalidArgumentException("Unsupported error correction level {$errorCorrection}"),
    };

    $matrix = Encoder::encode($data, $level)->getMatrix();
    $size = $matrix->getWidth();
    $rows = [];

    for ($y = 0; $y < $size; $y++) {
      for ($x = 0; $x < $size; $x++) {
        $rows[$y][$x] = $matrix->get($x, $y) === 1;
      }
    }

    return new self($size, $rows);
  }

  public function isDark(int $x, int $y): bool
  {
    return $this->rows[$y][$x];
  }
}
```

- [ ] **Step 5: Write `QrLogo` and `QrPdf`**

`components/shortlinks/qr/QrLogo.php`:

```php
<?php

namespace giantbits\crelish\components\shortlinks\qr;

use RuntimeException;

/**
 * A PNG or JPEG logo, flattened onto white.
 *
 * Flattening is required because FPDF cannot embed PNGs with an alpha channel,
 * and the logo sits on a white knockout anyway.
 */
final class QrLogo
{
  private function __construct(
    public readonly string $file,
    public readonly string $png,
    public readonly int $width,
    public readonly int $height,
  ) {
  }

  public static function fromFile(string $path): self
  {
    $info = is_file($path) ? @getimagesize($path) : false;

    if ($info === false) {
      throw new RuntimeException(sprintf('Logo file "%s" is missing or not an image.', basename($path)));
    }

    $source = match ($info[2]) {
      IMAGETYPE_PNG => @imagecreatefrompng($path),
      IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
      default => throw new RuntimeException('The QR logo must be a PNG or JPEG file.'),
    };

    if ($source === false) {
      throw new RuntimeException(sprintf('Logo file "%s" could not be read.', basename($path)));
    }

    [$width, $height] = [$info[0], $info[1]];
    $canvas = imagecreatetruecolor($width, $height);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

    ob_start();
    imagepng($canvas);
    $png = (string)ob_get_clean();

    $file = tempnam(sys_get_temp_dir(), 'crelish-qr-logo-');
    file_put_contents($file, $png);

    return new self($file, $png, $width, $height);
  }

  public function __destruct()
  {
    if (is_file($this->file)) {
      @unlink($this->file);
    }
  }
}
```

`components/shortlinks/qr/QrPdf.php`:

```php
<?php

namespace giantbits\crelish\components\shortlinks\qr;

use FPDF;

/**
 * FPDF with a single-path rectangle fill, so adjacent modules render without
 * hairline seams in viewers and RIPs.
 */
final class QrPdf extends FPDF
{
  /**
   * @param array<array{0:float,1:float,2:float,3:float}> $rects [x, y, width, height] in user units, origin top-left
   */
  public function fillRects(array $rects): void
  {
    $operators = [];

    foreach ($rects as [$x, $y, $width, $height]) {
      $operators[] = sprintf('%.3F %.3F %.3F %.3F re', $x * $this->k, ($this->h - $y) * $this->k, $width * $this->k, -$height * $this->k);
    }

    if ($operators !== []) {
      $this->_out(implode("\n", $operators) . ' f');
    }
  }
}
```

- [ ] **Step 6: Write `QrRenderer`**

`components/shortlinks/qr/QrRenderer.php`:

```php
<?php

namespace giantbits\crelish\components\shortlinks\qr;

use LogicException;

/**
 * Draws a QR matrix at an exact physical size as SVG, EPS, PDF and PNG.
 *
 * Geometry is in module units: the code is $matrix->size modules wide plus
 * $quietZone light modules on each side. The size in millimetres covers the
 * whole image including the quiet zone, so the file can be placed as-is.
 */
final class QrRenderer
{
  public const PNG_MIN_DPI = 300;

  /** Share of the code width (without quiet zone) the logo knockout may take */
  public const LOGO_SHARE = 0.22;

  /** @var array{0:int,1:int,2:int,3:int}|null [x, y, width, height] in code modules */
  private ?array $knockout = null;

  public function __construct(
    private readonly QrMatrix $matrix,
    private readonly float $sizeMm,
    private readonly int $quietZone,
    private readonly string $color,
    private readonly ?QrLogo $logo = null,
  ) {
    if ($logo !== null) {
      $this->knockout = $this->computeKnockout($logo);
    }
  }

  /**
   * @return array{0:int,1:int,2:int,3:int}|null
   */
  public function knockout(): ?array
  {
    return $this->knockout;
  }

  /**
   * Horizontal runs of dark modules outside the knockout
   *
   * @return array<array{0:int,1:int,2:int}> [x, y, length] in code modules
   */
  public function darkRuns(): array
  {
    $runs = [];
    $size = $this->matrix->size;

    for ($y = 0; $y < $size; $y++) {
      $start = null;

      for ($x = 0; $x <= $size; $x++) {
        $dark = $x < $size && $this->matrix->isDark($x, $y) && !$this->inKnockout($x, $y);

        if ($dark && $start === null) {
          $start = $x;
        } elseif (!$dark && $start !== null) {
          $runs[] = [$start, $y, $x - $start];
          $start = null;
        }
      }
    }

    return $runs;
  }

  public function svg(): string
  {
    $total = $this->totalModules();
    $quiet = $this->quietZone;
    $path = '';

    foreach ($this->darkRuns() as [$x, $y, $length]) {
      $path .= sprintf('M%d %dh%dv1h-%dz', $x + $quiet, $y + $quiet, $length, $length);
    }

    $size = self::number($this->sizeMm);
    $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
      . sprintf('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="%smm" height="%smm" viewBox="0 0 %d %d" shape-rendering="crispEdges">', $size, $size, $total, $total) . "\n"
      . sprintf('<rect width="%d" height="%d" fill="#ffffff"/>', $total, $total) . "\n"
      . sprintf('<path fill="%s" d="%s"/>', $this->color, $path) . "\n";

    if ($this->logo !== null) {
      [$x, $y, $width, $height] = $this->logoBox();
      $svg .= sprintf(
        '<image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="xMidYMid meet" xlink:href="data:image/png;base64,%s"/>' . "\n",
        self::number($x + $quiet), self::number($y + $quiet), self::number($width), self::number($height), base64_encode($this->logo->png)
      );
    }

    return $svg . "</svg>\n";
  }

  public function eps(): string
  {
    if ($this->logo !== null) {
      throw new LogicException('EPS output cannot embed a logo; use the PDF.');
    }

    $total = $this->totalModules();
    $points = $this->sizeMm / 25.4 * 72;
    $scale = self::number($points / $total);
    [$red, $green, $blue] = self::rgb($this->color);

    $lines = [
      '%!PS-Adobe-3.0 EPSF-3.0',
      '%%BoundingBox: 0 0 ' . (int)ceil($points) . ' ' . (int)ceil($points),
      '%%HiResBoundingBox: 0 0 ' . self::number($points) . ' ' . self::number($points),
      '%%Creator: crelish short links',
      '%%EndComments',
      'gsave',
      "{$scale} {$scale} scale",
      '1 1 1 setrgbcolor',
      "0 0 {$total} {$total} rectfill",
      sprintf('%s %s %s setrgbcolor', self::number($red / 255), self::number($green / 255), self::number($blue / 255)),
      'newpath',
    ];

    foreach ($this->darkRuns() as [$x, $y, $length]) {
      $left = $x + $this->quietZone;
      $bottom = $total - ($y + $this->quietZone) - 1;
      $lines[] = "{$left} {$bottom} moveto {$length} 0 rlineto 0 1 rlineto -{$length} 0 rlineto closepath";
    }

    array_push($lines, 'fill', 'grestore', 'showpage', '%%EOF');

    return implode("\n", $lines) . "\n";
  }

  public function pdf(): string
  {
    $module = $this->moduleMm();
    $quiet = $this->quietZone;

    $pdf = new QrPdf('P', 'mm', [$this->sizeMm, $this->sizeMm]);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetCreator('crelish short links');
    $pdf->AddPage();
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(0, 0, $this->sizeMm, $this->sizeMm, 'F');

    [$red, $green, $blue] = self::rgb($this->color);
    $pdf->SetFillColor($red, $green, $blue);
    $pdf->fillRects(array_map(
      static fn(array $run) => [($run[0] + $quiet) * $module, ($run[1] + $quiet) * $module, $run[2] * $module, $module],
      $this->darkRuns()
    ));

    if ($this->logo !== null) {
      [$x, $y, $width, $height] = $this->logoBox();
      $pdf->Image($this->logo->file, ($x + $quiet) * $module, ($y + $quiet) * $module, $width * $module, $height * $module, 'PNG');
    }

    return $pdf->Output('S');
  }

  public function png(): string
  {
    $total = $this->totalModules();
    $modulePx = (int)ceil($this->sizeMm / 25.4 * self::PNG_MIN_DPI / $total);
    $pixels = $modulePx * $total;
    $dpi = (int)round($pixels / ($this->sizeMm / 25.4));
    $quiet = $this->quietZone;

    $image = imagecreatetruecolor($pixels, $pixels);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    [$red, $green, $blue] = self::rgb($this->color);
    $foreground = imagecolorallocate($image, $red, $green, $blue);

    foreach ($this->darkRuns() as [$x, $y, $length]) {
      imagefilledrectangle(
        $image,
        ($x + $quiet) * $modulePx,
        ($y + $quiet) * $modulePx,
        ($x + $quiet + $length) * $modulePx - 1,
        ($y + $quiet + 1) * $modulePx - 1,
        $foreground
      );
    }

    if ($this->logo !== null) {
      [$x, $y, $width, $height] = $this->logoBox();
      $source = imagecreatefromstring($this->logo->png);
      imagecopyresampled(
        $image, $source,
        (int)round(($x + $quiet) * $modulePx), (int)round(($y + $quiet) * $modulePx), 0, 0,
        (int)round($width * $modulePx), (int)round($height * $modulePx), $this->logo->width, $this->logo->height
      );
    }

    imageresolution($image, $dpi, $dpi);
    ob_start();
    imagepng($image);

    return (string)ob_get_clean();
  }

  private function totalModules(): int
  {
    return $this->matrix->size + 2 * $this->quietZone;
  }

  private function moduleMm(): float
  {
    return $this->sizeMm / $this->totalModules();
  }

  /**
   * @return array{0:int,1:int,2:int,3:int}
   */
  private function computeKnockout(QrLogo $logo): array
  {
    $size = $this->matrix->size;
    $width = self::matchParity(max(3, (int)round($size * self::LOGO_SHARE)), $size);
    $height = self::matchParity(max(3, (int)ceil(($width - 1) * $logo->height / $logo->width) + 1), $size);
    $height = min($height, $width);

    return [intdiv($size - $width, 2), intdiv($size - $height, 2), $width, $height];
  }

  /**
   * Logo placement inside the knockout, keeping half a module of white around it
   *
   * @return array{0:float,1:float,2:float,3:float} [x, y, width, height] in code modules
   */
  private function logoBox(): array
  {
    [$kx, $ky, $kw, $kh] = $this->knockout;
    $scale = min(($kw - 1) / $this->logo->width, ($kh - 1) / $this->logo->height);
    $width = $this->logo->width * $scale;
    $height = $this->logo->height * $scale;

    return [$kx + ($kw - $width) / 2, $ky + ($kh - $height) / 2, $width, $height];
  }

  private function inKnockout(int $x, int $y): bool
  {
    if ($this->knockout === null) {
      return false;
    }

    [$kx, $ky, $kw, $kh] = $this->knockout;

    return $x >= $kx && $x < $kx + $kw && $y >= $ky && $y < $ky + $kh;
  }

  /**
   * Same parity as the code size keeps the knockout centred on the module grid
   */
  private static function matchParity(int $value, int $size): int
  {
    return ($size - $value) % 2 === 0 ? $value : $value + 1;
  }

  /**
   * @return array{0:int,1:int,2:int}
   */
  private static function rgb(string $hex): array
  {
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
  }

  private static function number(float $value): string
  {
    return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
  }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php tests/QrRenderTest.php`
Expected: all `ok`, exit 0. (Reference values: at level H the payload is 33 modules; knockout width round(33 × 0.22) = 7; the 2:1 test logo needs ceil(6 × 0.5) + 1 = 4 rows, raised to 5 for parity with 33; origin (13, 14), centre (16, 16). The PNG is 11 px per module × 33 = 363 px, 363 / (30 / 25.4) = 307 dpi.)

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock components/shortlinks/qr tests/QrRenderTest.php
git commit -m "feat(shortlinks): exact-size QR rendering in SVG, EPS, PDF and PNG"
```

---

### Task 7: QR bundle

**Files:**
- Create: `components/shortlinks/QrBundleService.php`
- Test: `tests/QrBundleTest.php`

**Interfaces:**
- Consumes: `QrMatrix`, `QrLogo`, `QrRenderer` (Task 6), `ShortLink::getQrPayload/getShortUrl`, `ShortLinkConfig::qrLogoPath`, `CrelishBaseHelper::getAssetUrlById(string $uuid): ?string`
- Produces:
  - `new QrBundleService(?string $logoPath)`; `static forLink(ShortLink $link): QrBundleService`; `static logoPathFor(ShortLink $link, ?callable $assetUrl = null): ?string`
  - `files(ShortLink $link): array<string,string>` (entry name => content), `zip(ShortLink $link): string` (temp file path), `renderer(ShortLink $link, string $variant /* 'plain'|'logo' */): QrRenderer` (throws `RuntimeException` for `logo` without a usable logo), `static fileNames(ShortLink $link, bool $withLogo): string[]`
  - public `?string $logoError`; constants `PLAIN_MIN_MM = 15`, `LOGO_MIN_MM = 25`

- [ ] **Step 1: Write the failing test**

`tests/QrBundleTest.php`:

```php
<?php

/**
 * QR bundle: file set with and without logo, README, ZIP, logo lookup.
 *
 * Run with:  php tests/QrBundleTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\shortlinks\QrBundleService;
use giantbits\crelish\models\ShortLink;

function bundleLink(array $attributes = []): ShortLink
{
    $link = new ShortLink();
    $link->setAttributes(array_merge([
        'systitle' => 'IHF 2026 Flyer', 'code' => 'ihf26', 'state' => 2,
        'target_type' => ShortLink::TARGET_URL, 'target_url' => 'https://www.example.com/ihf',
        'qr_size_mm' => 30, 'qr_color' => '#000000', 'qr_quiet_zone' => 4,
    ], $attributes), false);

    return $link;
}

function squareLogo(): string
{
    $image = imagecreatetruecolor(300, 300);
    imagefill($image, 0, 0, imagecolorallocate($image, 47, 111, 79));
    $path = tempnam(sys_get_temp_dir(), 'qr-bundle-logo-') . '.png';
    imagepng($image, $path);

    return $path;
}

shortLinkApp();
$plainNames = ['plain/ihf26.svg', 'plain/ihf26.eps', 'plain/ihf26.pdf', 'plain/ihf26.png', 'README.txt'];
$logoNames = ['logo/ihf26.svg', 'logo/ihf26.pdf', 'logo/ihf26.png'];

echo "Without logo\n";
$service = new QrBundleService(null);
$files = $service->files(bundleLink());
check('plain files and README only', $plainNames, array_keys($files));
check('README names the short URL', true, str_contains($files['README.txt'], 'https://forum-holzbau.test/go/ihf26'));
check('README names the QR content', true, str_contains($files['README.txt'], 'HTTPS://FORUM-HOLZBAU.TEST/GO/IHF26/Q'));
check('README states the size', true, str_contains($files['README.txt'], '30 mm'));
check('fileNames matches the plain bundle', array_slice($plainNames, 0, 4), QrBundleService::fileNames(bundleLink(), false));

echo "\nWith logo\n";
$service = new QrBundleService(squareLogo());
$files = $service->files(bundleLink());
check('logo variants are added', array_merge(array_slice($plainNames, 0, 4), $logoNames, ['README.txt']), array_keys($files));
check('no EPS with logo', false, isset($files['logo/ihf26.eps']));
check('no logo error', null, $service->logoError);
check('README explains the logo minimum size', true, str_contains($files['README.txt'], '25 mm'));
check('fileNames matches the logo bundle', array_merge(array_slice($plainNames, 0, 4), $logoNames), QrBundleService::fileNames(bundleLink(), true));

$zipPath = $service->zip(bundleLink());
$zip = new ZipArchive();
$zip->open($zipPath);
check('zip contains all eight files', 8, $zip->numFiles);
$zip->close();
unlink($zipPath);

echo "\nBroken logo\n";
$service = new QrBundleService('/nonexistent/logo.png');
$files = $service->files(bundleLink());
check('broken logo falls back to plain only', $plainNames, array_keys($files));
check('logo error is reported', true, $service->logoError !== null);
check('README says why the logo is missing', true, str_contains($files['README.txt'], 'logo/ was not created'));
try {
    $service->renderer(bundleLink(), 'logo');
    $threw = false;
} catch (RuntimeException) {
    $threw = true;
}
check('logo preview without a usable logo throws', true, $threw);

echo "\nLogo lookup\n";
$webroot = sys_get_temp_dir() . '/qr-webroot-' . uniqid();
mkdir($webroot . '/uploads', 0777, true);
Yii::setAlias('@webroot', $webroot);
check('asset override is a file under webroot', $webroot . '/uploads/logo.png',
    QrBundleService::logoPathFor(bundleLink(['logo_asset_uuid' => 'asset-1']), fn(string $uuid) => $uuid === 'asset-1' ? '/uploads/logo.png' : null));
shortLinkApp(['shortLinks' => ['qrLogo' => '/srv/logo.png']]);
check('without override the site logo is used', '/srv/logo.png', QrBundleService::logoPathFor(bundleLink(), fn() => null));
check('unknown asset falls back to the site logo', '/srv/logo.png', QrBundleService::logoPathFor(bundleLink(['logo_asset_uuid' => 'gone']), fn() => null));
shortLinkApp();
check('no logo anywhere', null, QrBundleService::logoPathFor(bundleLink(), fn() => null));

shortLinkDone();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/QrBundleTest.php`
Expected: fatal error, class `QrBundleService` not found.

- [ ] **Step 3: Write the service**

`components/shortlinks/QrBundleService.php`:

```php
<?php

namespace giantbits\crelish\components\shortlinks;

use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\shortlinks\qr\QrLogo;
use giantbits\crelish\components\shortlinks\qr\QrMatrix;
use giantbits\crelish\components\shortlinks\qr\QrRenderer;
use giantbits\crelish\models\ShortLink;
use RuntimeException;
use Yii;
use ZipArchive;

/**
 * Builds the print bundle for a short link: plain and (if a logo is set)
 * logo variants plus a README.
 */
final class QrBundleService
{
  public const PLAIN_MIN_MM = 15;
  public const LOGO_MIN_MM = 25;

  public ?string $logoError = null;

  public function __construct(private readonly ?string $logoPath)
  {
  }

  public static function forLink(ShortLink $link): self
  {
    return new self(self::logoPathFor($link));
  }

  /**
   * The link's own logo asset if set, else the site-wide logo from config
   *
   * @param callable(string): ?string|null $assetUrl resolves an asset uuid to its site-relative URL
   */
  public static function logoPathFor(ShortLink $link, ?callable $assetUrl = null): ?string
  {
    if (!empty($link->logo_asset_uuid)) {
      $url = ($assetUrl ?? [CrelishBaseHelper::class, 'getAssetUrlById'])($link->logo_asset_uuid);

      if ($url) {
        return Yii::getAlias('@webroot') . '/' . ltrim($url, '/');
      }
    }

    return ShortLinkConfig::qrLogoPath();
  }

  /**
   * @return string[] downloadable file names, without README
   */
  public static function fileNames(ShortLink $link, bool $withLogo): array
  {
    $names = [];

    foreach (['svg', 'eps', 'pdf', 'png'] as $extension) {
      $names[] = "plain/{$link->code}.{$extension}";
    }

    if ($withLogo) {
      foreach (['svg', 'pdf', 'png'] as $extension) {
        $names[] = "logo/{$link->code}.{$extension}";
      }
    }

    return $names;
  }

  public function renderer(ShortLink $link, string $variant): QrRenderer
  {
    $payload = $link->getQrPayload();

    if ($variant !== 'logo') {
      return new QrRenderer(QrMatrix::encode($payload, 'M'), (float)$link->qr_size_mm, (int)$link->qr_quiet_zone, (string)$link->qr_color);
    }

    if ($this->logoPath === null) {
      throw new RuntimeException('No QR logo is configured.');
    }

    return new QrRenderer(QrMatrix::encode($payload, 'H'), (float)$link->qr_size_mm, (int)$link->qr_quiet_zone, (string)$link->qr_color, QrLogo::fromFile($this->logoPath));
  }

  /**
   * @return array<string,string> ZIP entry name => content
   */
  public function files(ShortLink $link): array
  {
    $this->logoError = null;
    $code = $link->code;

    $plain = $this->renderer($link, 'plain');
    $files = [
      "plain/{$code}.svg" => $plain->svg(),
      "plain/{$code}.eps" => $plain->eps(),
      "plain/{$code}.pdf" => $plain->pdf(),
      "plain/{$code}.png" => $plain->png(),
    ];

    if ($this->logoPath !== null) {
      try {
        $withLogo = $this->renderer($link, 'logo');
        $files["logo/{$code}.svg"] = $withLogo->svg();
        $files["logo/{$code}.pdf"] = $withLogo->pdf();
        $files["logo/{$code}.png"] = $withLogo->png();
      } catch (RuntimeException $e) {
        $this->logoError = $e->getMessage();
        Yii::warning("QR logo for short link '{$code}' skipped: " . $e->getMessage(), 'shortlink');
      }
    }

    $files['README.txt'] = $this->readme($link, isset($files["logo/{$code}.png"]));

    return $files;
  }

  /**
   * @return string path of a temporary ZIP file; the caller deletes it
   */
  public function zip(ShortLink $link): string
  {
    $files = $this->files($link);
    $path = tempnam(sys_get_temp_dir(), 'crelish-qr-');
    $zip = new ZipArchive();

    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      throw new RuntimeException('Could not create the QR code ZIP file.');
    }

    foreach ($files as $name => $content) {
      $zip->addFromString($name, $content);
    }

    $zip->close();

    return $path;
  }

  private function readme(ShortLink $link, bool $hasLogo): string
  {
    $lines = [
      "QR code for: {$link->systitle}",
      'Short URL:   ' . $link->getShortUrl(),
      'QR content:  ' . $link->getQrPayload(),
      '',
      "Size: {$link->qr_size_mm} mm including the {$link->qr_quiet_zone}-module white quiet zone.",
      'Place the files as they are and do not crop the white border.',
      '',
      'plain/  SVG, EPS and PDF (vector) and PNG (at least ' . QrRenderer::PNG_MIN_DPI . ' dpi).',
      '        Readable from ' . self::PLAIN_MIN_MM . ' mm.',
    ];

    if ($hasLogo) {
      $lines[] = 'logo/   SVG, PDF (vector) and PNG with the logo in the centre.';
      $lines[] = '        Use only at ' . self::LOGO_MIN_MM . ' mm or larger; for smaller print use plain/.';
      $lines[] = '        EPS cannot embed the logo; use the PDF instead.';
    } elseif ($this->logoError !== null) {
      $lines[] = 'logo/ was not created: ' . $this->logoError;
    }

    return implode("\n", $lines) . "\n";
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/QrBundleTest.php && php tests/QrRenderTest.php`
Expected: all `ok`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add components/shortlinks/QrBundleService.php tests/QrBundleTest.php
git commit -m "feat(shortlinks): QR print bundle with README and ZIP export"
```

---

### Task 8: Statistics and title lookup

**Files:**
- Create: `components/shortlinks/ShortLinkStats.php`
- Modify: `components/ElementTitleResolver.php` (add a constant; merge it in `getConfig()`)
- Test: `tests/ShortLinkStatsTest.php`

**Interfaces:**
- Consumes: tables `analytics_element_daily`, `analytics_element_views`, `analytics_sessions`
- Produces:
  - `ShortLinkStats::TYPES = ['scan', 'click', 'fallback']`
  - `forLink(string $uuid, string $startDate, string $endDate): array{totals: array<string,int>, uniqueSessions: int, days: array<string, array<string,int>>}`
  - `summaries(array $uuids, int $days = 30): array<string, array{scan:int, click:int, fallback:int, last:?string}>`
  - `lastAggregatedDate(): ?string`
  - `ElementTitleResolver::resolve($uuid, 'shortlink')` returns the link's `systitle`

- [ ] **Step 1: Write the failing test**

`tests/ShortLinkStatsTest.php`:

```php
<?php

/**
 * Short link statistics: aggregated days plus raw events after the last
 * aggregation, bot filtering, no double counting; title lookup.
 *
 * Run with:  php tests/ShortLinkStatsTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\ElementTitleResolver;
use giantbits\crelish\components\shortlinks\ShortLinkStats;
use giantbits\crelish\models\ShortLink;

const A = 'd0000000-0000-4000-8000-00000000000a';
const B = 'd0000000-0000-4000-8000-00000000000b';

function day(int $ago): string
{
    return date('Y-m-d', strtotime("-{$ago} days"));
}

function daily(string $uuid, string $date, string $type, int $views, int $sessions, string $elementType = 'shortlink'): void
{
    Yii::$app->db->createCommand()->insert('analytics_element_daily', [
        'date' => $date, 'element_uuid' => $uuid, 'element_type' => $elementType, 'page_uuid' => $uuid,
        'event_type' => $type, 'total_views' => $views, 'unique_sessions' => $sessions,
    ])->execute();
}

function raw(string $uuid, string $date, string $type, string $session, int $isBot = 0, string $elementType = 'shortlink'): void
{
    $db = Yii::$app->db;
    if (!(new yii\db\Query())->from('analytics_sessions')->where(['session_id' => $session])->exists()) {
        $db->createCommand()->insert('analytics_sessions', ['session_id' => $session, 'is_bot' => $isBot])->execute();
    }
    $db->createCommand()->insert('analytics_element_views', [
        'element_uuid' => $uuid, 'element_type' => $elementType, 'page_uuid' => $uuid,
        'session_id' => $session, 'type' => $type, 'created_at' => $date . ' 10:00:00',
    ])->execute();
}

shortLinkApp();

// Aggregated up to two days ago
daily(A, day(4), 'scan', 5, 4);
daily(A, day(2), 'scan', 3, 3);
daily(A, day(2), 'click', 2, 2);
daily(B, day(2), 'scan', 7, 7);
daily(A, day(2), 'view', 99, 99, 'news');
// Raw rows from an already aggregated day must not be counted twice
raw(A, day(2), 'scan', 's-old');
// Raw rows after the last aggregation
raw(A, day(1), 'scan', 's1');
raw(A, day(1), 'scan', 's1');
raw(A, day(0), 'click', 's2');
raw(A, day(0), 'fallback', 's3');
raw(A, day(0), 'scan', 'bot', 1);
raw(A, day(0), 'click', 's4', 0, 'news');

$stats = new ShortLinkStats();
check('last aggregated date', day(2), $stats->lastAggregatedDate());

echo "Per link\n";
$result = $stats->forLink(A, day(6), day(0));
check('totals combine aggregated and raw data', ['scan' => 10, 'click' => 3, 'fallback' => 1], $result['totals']);
check('unique sessions are summed per day', 4 + 3 + 2 + 1 + 1 + 1, $result['uniqueSessions']);
check('every day in the range is present', 7, count($result['days']));
check('empty days are zero', ['scan' => 0, 'click' => 0, 'fallback' => 0], $result['days'][day(6)]);
check('raw day counts', ['scan' => 2, 'click' => 0, 'fallback' => 0], $result['days'][day(1)]);
check('bots are excluded from raw data', 0, $result['days'][day(0)]['scan']);

echo "\nSummaries\n";
$summaries = $stats->summaries([A, B], 30);
check('summary for A', [10, 3, 1], [$summaries[A]['scan'], $summaries[A]['click'], $summaries[A]['fallback']]);
check('summary for B', [7, 0, 0], [$summaries[B]['scan'], $summaries[B]['click'], $summaries[B]['fallback']]);
check('last hit ignores bots', day(0) . ' 10:00:00', $summaries[A]['last']);
check('no raw hits means no last hit', null, $summaries[B]['last']);
check('empty uuid list', [], $stats->summaries([]));

echo "\nNever aggregated\n";
shortLinkApp();
raw(A, day(0), 'scan', 's1');
check('raw data alone is used', 1, (new ShortLinkStats())->forLink(A, day(1), day(0))['totals']['scan']);

echo "\nTitle lookup\n";
$link = new ShortLink();
$link->setAttributes(['systitle' => 'IHF 2026 Flyer', 'code' => 'ihf26', 'state' => 2, 'target_type' => 'url', 'target_url' => 'https://www.example.com/'], false);
$link->save(false);
ElementTitleResolver::clearCache();
check('analytics widgets can name a short link', 'IHF 2026 Flyer', ElementTitleResolver::resolve($link->uuid, 'shortlink'));

shortLinkDone();
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/ShortLinkStatsTest.php`
Expected: fatal error, class `ShortLinkStats` not found.

- [ ] **Step 3: Write `ShortLinkStats`**

`components/shortlinks/ShortLinkStats.php`:

```php
<?php

namespace giantbits\crelish\components\shortlinks;

use yii\db\Expression;
use yii\db\Query;

/**
 * Short link numbers for the admin.
 *
 * Days up to the last nightly aggregation come from analytics_element_daily;
 * later days come from raw events, filtered for bots the same way the nightly
 * job does. Using the last aggregated date as the boundary avoids both gaps
 * (job not run yet) and double counting.
 */
final class ShortLinkStats
{
  public const TYPES = ['scan', 'click', 'fallback'];

  public function lastAggregatedDate(): ?string
  {
    $date = (new Query())->from('{{%analytics_element_daily}}')->max('date');

    return $date ? substr((string)$date, 0, 10) : null;
  }

  /**
   * @return array{totals: array<string,int>, uniqueSessions: int, days: array<string, array<string,int>>}
   */
  public function forLink(string $uuid, string $startDate, string $endDate): array
  {
    $totals = array_fill_keys(self::TYPES, 0);
    $days = [];
    $unique = 0;

    for ($date = $startDate; $date <= $endDate; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
      $days[$date] = array_fill_keys(self::TYPES, 0);
    }

    foreach ($this->rows([$uuid], $startDate, $endDate) as $row) {
      if (!isset($totals[$row['type']], $days[$row['date']])) {
        continue;
      }

      $days[$row['date']][$row['type']] += (int)$row['views'];
      $totals[$row['type']] += (int)$row['views'];
      $unique += (int)$row['sessions'];
    }

    return ['totals' => $totals, 'uniqueSessions' => $unique, 'days' => $days];
  }

  /**
   * @param string[] $uuids
   * @return array<string, array{scan:int, click:int, fallback:int, last:?string}>
   */
  public function summaries(array $uuids, int $days = 30): array
  {
    $result = [];

    foreach ($uuids as $uuid) {
      $result[$uuid] = ['scan' => 0, 'click' => 0, 'fallback' => 0, 'last' => null];
    }

    if ($uuids === []) {
      return $result;
    }

    $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

    foreach ($this->rows($uuids, $startDate, date('Y-m-d')) as $row) {
      if (isset($result[$row['uuid']][$row['type']])) {
        $result[$row['uuid']][$row['type']] += (int)$row['views'];
      }
    }

    $last = (new Query())
      ->select(['uuid' => 'ev.element_uuid', 'last' => new Expression('MAX(ev.created_at)')])
      ->from(['ev' => '{{%analytics_element_views}}'])
      ->innerJoin(['s' => '{{%analytics_sessions}}'], 's.session_id = ev.session_id')
      ->where(['ev.element_type' => 'shortlink', 'ev.element_uuid' => $uuids, 's.is_bot' => 0])
      ->groupBy('ev.element_uuid')
      ->all();

    foreach ($last as $row) {
      $result[$row['uuid']]['last'] = $row['last'];
    }

    return $result;
  }

  /**
   * @return list<array{uuid: string, date: string, type: string, views: int|string, sessions: int|string}>
   */
  private function rows(array $uuids, string $startDate, string $endDate): array
  {
    $lastAggregated = $this->lastAggregatedDate();
    $rows = [];

    if ($lastAggregated !== null) {
      $aggregatedEnd = min($endDate, $lastAggregated);

      if ($startDate <= $aggregatedEnd) {
        $rows = (new Query())
          ->select([
            'uuid' => 'element_uuid',
            'date' => 'date',
            'type' => 'event_type',
            'views' => new Expression('SUM(total_views)'),
            'sessions' => new Expression('SUM(unique_sessions)'),
          ])
          ->from('{{%analytics_element_daily}}')
          ->where(['element_type' => 'shortlink', 'element_uuid' => $uuids])
          ->andWhere(['between', 'date', $startDate, $aggregatedEnd])
          ->groupBy(['element_uuid', 'date', 'event_type'])
          ->all();
      }
    }

    $rawStart = $lastAggregated !== null
      ? max($startDate, date('Y-m-d', strtotime($lastAggregated . ' +1 day')))
      : $startDate;

    if ($rawStart <= $endDate) {
      $day = new Expression('DATE(ev.created_at)');
      $raw = (new Query())
        ->select([
          'uuid' => 'ev.element_uuid',
          'date' => $day,
          'type' => 'ev.type',
          'views' => new Expression('COUNT(*)'),
          'sessions' => new Expression('COUNT(DISTINCT ev.session_id)'),
        ])
        ->from(['ev' => '{{%analytics_element_views}}'])
        ->innerJoin(['s' => '{{%analytics_sessions}}'], 's.session_id = ev.session_id')
        ->where(['ev.element_type' => 'shortlink', 'ev.element_uuid' => $uuids, 's.is_bot' => 0])
        ->andWhere(['>=', 'ev.created_at', $rawStart . ' 00:00:00'])
        ->andWhere(['<', 'ev.created_at', date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00'])
        ->groupBy(['ev.element_uuid', $day, 'ev.type'])
        ->all();

      $rows = array_merge($rows, $raw);
    }

    return $rows;
  }
}
```

- [ ] **Step 4: Register `shortlink` in `ElementTitleResolver`**

In `components/ElementTitleResolver.php`, add below `private static ?array $config = null;`:

```php
    /** Built-in crelish element types; the project config file can override them */
    private const BUILTIN_TYPES = [
        'shortlink' => ['table' => 'shortlink', 'titleFields' => ['systitle']],
    ];
```

In `getConfig()`, directly before its **final** `return self::$config;` (not the early return inside `if (self::$config !== null)`), add:

```php
        self::$config = array_merge(self::BUILTIN_TYPES, self::$config);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/ShortLinkStatsTest.php`
Expected: all `ok`, exit 0.

- [ ] **Step 6: Commit**

```bash
git add components/shortlinks/ShortLinkStats.php components/ElementTitleResolver.php tests/ShortLinkStatsTest.php
git commit -m "feat(shortlinks): statistics from aggregated and raw analytics"
```

---

### Task 9: Enable locally in forum-holzbau

This task runs in **`/Users/smyr/Sites/gbits/crelish.forum-holzbau`**. It makes the feature visible locally for the admin tasks that follow.

**Files:**
- Modify: `config/params.php` (inside the `'crelish' => [...]` array)

- [ ] **Step 1: Create a branch**

```bash
cd /Users/smyr/Sites/gbits/crelish.forum-holzbau
git checkout -b feature/shortlinks
```

- [ ] **Step 2: Install crelish's new dependencies locally**

```bash
composer update giantbits/yii2-crelish --with-dependencies
```

Expected: bacon/bacon-qr-code and setasign/fpdf are installed; `vendor/giantbits/yii2-crelish` is still a symlink (`ls -la vendor/giantbits`). `composer.lock` is not tracked here, so nothing needs committing from this step.

- [ ] **Step 3: Add the config**

In `config/params.php`, inside `'crelish' => [`, directly after the `'entryPoint' => [...]` block, add:

```php
    'shortLinks' => [
      'enabled' => true,
      'detailPages' => [
        'news' => 'news',
        'constructions' => 'bauten',
      ],
      // The theme only has wide wordmarks, which become unreadably thin in a
      // QR code's centre. Set this once a square signet PNG (>= 600 px) exists.
      'qrLogo' => null,
    ],
```

- [ ] **Step 4: Run the migration locally**

```bash
php yii crelish-migrate --interactive=0
```

Expected: `*** applied m260924_120000_create_shortlink_table`.

- [ ] **Step 5: Smoke-test the redirect**

Start the local site the way the user normally serves it (ask the user for the local URL if unknown). Insert one link through the app:

```bash
php -r 'require "vendor/autoload.php"; require "vendor/yiisoft/yii2/Yii.php"; (Dotenv\Dotenv::createImmutable(getcwd()))->safeLoad(); $c = require "config/console.php"; new yii\console\Application($c); $l = new giantbits\crelish\models\ShortLink(["systitle"=>"Smoke test","code"=>"smoke1","state"=>2,"target_type"=>"url","target_url"=>"https://www.example.com/"]); var_dump($l->save(), $l->getErrors());'
```

Expected: `bool(true)` and an empty error array. Then `curl -sI <local-url>/go/smoke1/q` shows `HTTP/1.1 302`, `Location: https://www.example.com/`, `Cache-Control: no-store`, `X-Robots-Tag: noindex`. Delete the row afterwards: `DELETE FROM shortlink WHERE code='smoke1';` (through the same `php -r` pattern with `Yii::$app->db->createCommand()->delete('shortlink', ['code'=>'smoke1'])->execute();`).

- [ ] **Step 6: Commit (forum-holzbau)**

```bash
git add config/params.php
git commit -m "feat(shortlinks): Kurzlinks aktivieren (news, bauten)"
```

---

### Task 10: Admin list, edit and delete

Back in **crelish**.

**Files:**
- Create: `controllers/ShortLinkController.php`
- Create: `views/short-link/index.twig`
- Create: `views/short-link/edit.twig`
- Modify: `config/sidebar.json` (new item)
- Modify: `components/CrelishSidebarManager.php` (`evaluateCondition`)

**Interfaces:**
- Consumes: everything from Tasks 1–8. `AssetConnector` widget config: `formKey`, `field` (object with `key`, `label`, `config`, `rules`), `data`, `model`. It posts `CrelishDynamicModel[logo_asset_uuid]`.
- Produces: admin routes `crelish/short-link/{index,create,update,delete,targets,qr-preview,qr-download}`. This task writes `index`, `create`, `update`, `delete`, `targets`; Task 11 adds the QR and statistics parts of `edit.twig` and the two QR actions.

- [ ] **Step 1: Add the sidebar item and condition**

In `config/sidebar.json`, insert after the `"asset"` item (order 40):

```json
    {
      "id": "shortlinks",
      "label": "Short Links",
      "url": "crelish/short-link/index",
      "icon": "fa-sharp fa-regular fa-link",
      "order": 45,
      "condition": "shortlinks"
    },
```

In `components/CrelishSidebarManager.php`, at the start of `evaluateCondition()`:

```php
    if ($condition === 'shortlinks') {
      return \giantbits\crelish\components\shortlinks\ShortLinkConfig::isEnabled();
    }
```

- [ ] **Step 2: Write the controller**

`controllers/ShortLinkController.php`:

```php
<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishModelResolver;
use giantbits\crelish\components\ElementTitleResolver;
use giantbits\crelish\components\shortlinks\QrBundleService;
use giantbits\crelish\components\shortlinks\ShortLinkCode;
use giantbits\crelish\components\shortlinks\ShortLinkConfig;
use giantbits\crelish\components\shortlinks\ShortLinkResolver;
use giantbits\crelish\components\shortlinks\ShortLinkStats;
use giantbits\crelish\helpers\CrelishAnalyticsPeriod;
use giantbits\crelish\models\ShortLink;
use giantbits\crelish\plugins\assetconnector\AssetConnector;
use giantbits\crelish\widgets\CrelishAnalyticsPeriodPicker;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Admin for short links: list, edit, QR export and statistics.
 */
class ShortLinkController extends CrelishBaseController
{
  public $layout = 'crelish.twig';

  private const BADGES = [
    ShortLink::STATUS_ONLINE => 'success',
    ShortLink::STATUS_SCHEDULED => 'info',
    ShortLink::STATUS_EXPIRED => 'secondary',
    'broken' => 'danger',
    ShortLink::STATUS_DRAFT => 'warning',
    ShortLink::STATUS_OFFLINE => 'secondary',
    ShortLink::STATUS_ARCHIVED => 'dark',
  ];

  public function behaviors(): array
  {
    return [
      'access' => [
        'class' => AccessControl::class,
        'rules' => [['allow' => true, 'roles' => ['@']]],
      ],
    ];
  }

  public function beforeAction($action): bool
  {
    if (!ShortLinkConfig::isEnabled()) {
      throw new NotFoundHttpException();
    }

    return parent::beforeAction($action);
  }

  protected function setupHeaderBar()
  {
    parent::setupHeaderBar();

    // The generic bulk delete posts to the content controller; single delete lives on the edit view
    if ($this->action && $this->action->id === 'index') {
      $this->view->params['headerBarRight'] = ['create'];
    }
  }

  public static function statusLabels(): array
  {
    return [
      ShortLink::STATUS_ONLINE => Yii::t('crelish', 'Online'),
      ShortLink::STATUS_SCHEDULED => Yii::t('crelish', 'Scheduled'),
      ShortLink::STATUS_EXPIRED => Yii::t('crelish', 'Expired'),
      'broken' => Yii::t('crelish', 'Broken target'),
      ShortLink::STATUS_DRAFT => Yii::t('crelish', 'Draft'),
      ShortLink::STATUS_OFFLINE => Yii::t('crelish', 'Offline'),
      ShortLink::STATUS_ARCHIVED => Yii::t('crelish', 'Archived'),
    ];
  }

  public function actionIndex(): string
  {
    $this->view->title = Yii::t('crelish', 'Short Links');
    $request = Yii::$app->request;
    $search = trim((string)$request->get('cr_content_filter', ''));
    $status = (string)$request->get('cr_status_filter', '');
    $now = time();

    $query = ShortLink::find()->orderBy(['updated' => SORT_DESC]);

    if ($search !== '') {
      $query->andWhere(['or', ['like', 'systitle', $search], ['like', 'code', $search]]);
    }

    $byState = [ShortLink::STATUS_OFFLINE => 0, ShortLink::STATUS_DRAFT => 1, ShortLink::STATUS_ARCHIVED => 3];

    if (isset($byState[$status])) {
      $query->andWhere(['state' => $byState[$status]]);
    } elseif ($status === ShortLink::STATUS_SCHEDULED) {
      $query->andWhere(['state' => ShortLink::STATE_ONLINE])->andWhere(['>', 'valid_from', $now]);
    } elseif ($status === ShortLink::STATUS_EXPIRED) {
      $query->andWhere(['state' => ShortLink::STATE_ONLINE])->andWhere(['<', 'valid_until', $now]);
    } elseif ($status === ShortLink::STATUS_ONLINE || $status === 'broken') {
      $query->andWhere(['state' => ShortLink::STATE_ONLINE])
        ->andWhere(['or', ['valid_from' => null], ['<=', 'valid_from', $now]])
        ->andWhere(['or', ['valid_until' => null], ['>=', 'valid_until', $now]]);
    }

    $dataProvider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 25]]);
    $links = $dataProvider->getModels();
    $summaries = (new ShortLinkStats())->summaries(array_map(fn(ShortLink $link) => $link->uuid, $links));
    $resolver = new ShortLinkResolver();
    $rows = [];

    foreach ($links as $link) {
      $linkStatus = $link->getStatus($now);

      if ($linkStatus === ShortLink::STATUS_ONLINE && $link->target_type === ShortLink::TARGET_CONTENT && $resolver->resolveContent($link) === null) {
        $linkStatus = 'broken';
      }

      $rows[$link->uuid] = [
        'status' => $linkStatus,
        'badge' => self::BADGES[$linkStatus],
        'target' => $this->targetLabel($link),
        'stats' => $summaries[$link->uuid],
      ];
    }

    if ($status === 'broken') {
      $rows = array_filter($rows, fn(array $row) => $row['status'] === 'broken');
    }

    return $this->render('index.twig', [
      'dataProvider' => $dataProvider,
      'rows' => $rows,
      'status' => $status,
      'statuses' => self::statusLabels(),
    ]);
  }

  public function actionCreate()
  {
    $link = new ShortLink();
    $link->state = ShortLink::STATE_ONLINE;
    $link->target_type = ShortLink::TARGET_URL;
    $link->qr_size_mm = 30;
    $link->qr_color = '#000000';
    $link->qr_quiet_zone = 4;
    $link->code = ShortLinkCode::generate(fn(string $code) => ShortLink::find()->where(['code' => $code])->exists());

    return $this->edit($link);
  }

  public function actionUpdate(string $uuid)
  {
    return $this->edit($this->findLink($uuid));
  }

  public function actionDelete(string $uuid): Response
  {
    if ($this->findLink($uuid)->delete()) {
      Yii::$app->session->setFlash('success', Yii::t('crelish', 'Short link deleted.'));
    } else {
      Yii::$app->session->setFlash('warning', Yii::t('crelish', 'Short link could not be deleted.'));
    }

    return $this->redirect(['index']);
  }

  /**
   * Record search for the target picker
   */
  public function actionTargets(string $ctype, string $q = ''): array
  {
    Yii::$app->response->format = Response::FORMAT_JSON;
    $q = trim($q);

    if (mb_strlen($q) < 2 || !in_array($ctype, ShortLinkResolver::resolvableTypes(), true)) {
      return [];
    }

    try {
      $class = CrelishModelResolver::getModelClass($ctype);
    } catch (\InvalidArgumentException) {
      return [];
    }

    $records = $class::find()
      ->select(['uuid', 'systitle'])
      ->where(['like', 'systitle', $q])
      ->orderBy(['systitle' => SORT_ASC])
      ->limit(20)
      ->asArray()
      ->all();

    return array_map(fn(array $record) => ['uuid' => $record['uuid'], 'title' => (string)$record['systitle']], $records);
  }

  private function edit(ShortLink $link)
  {
    $request = Yii::$app->request;

    if ($request->isPost && $link->loadForm($request->post())) {
      if ($link->save()) {
        Yii::$app->session->setFlash('success', Yii::t('crelish', 'Short link saved.'));

        return $request->post('save_n_return') === '1'
          ? $this->redirect(['index'])
          : $this->redirect(['update', 'uuid' => $link->uuid]);
      }

      Yii::$app->session->setFlash('error', Yii::t('crelish', 'Please correct the highlighted fields.'));
    }

    $this->view->title = $link->isNewRecord ? Yii::t('crelish', 'New short link') : $link->systitle;

    return $this->render('edit.twig', array_merge([
      'link' => $link,
      'targetTypes' => ShortLinkResolver::resolvableTypes(),
      'targetTitle' => $link->target_uuid ? ElementTitleResolver::resolve($link->target_uuid, (string)$link->target_ctype) : '',
      'languages' => Yii::$app->params['crelish']['languages'] ?? [],
      'siteFallback' => ShortLinkConfig::siteFallbackUrl(),
      'resolvesTo' => $link->isNewRecord ? null : (new ShortLinkResolver())->resolve($link),
      'csrfParam' => $request->csrfParam,
      'csrfToken' => $request->csrfToken,
    ], $this->qrAndStatsParams($link)));
  }

  /**
   * View parameters for the QR block and statistics tab (filled in Task 11)
   */
  protected function qrAndStatsParams(ShortLink $link): array
  {
    return [];
  }

  private function targetLabel(ShortLink $link): string
  {
    if ($link->target_type === ShortLink::TARGET_URL) {
      return mb_strimwidth((string)$link->target_url, 0, 60, '…');
    }

    $title = ElementTitleResolver::resolve((string)$link->target_uuid, (string)$link->target_ctype);

    return $link->target_ctype . ': ' . ($title ?? $link->target_uuid);
  }

  private function findLink(string $uuid): ShortLink
  {
    $link = ShortLink::findOne($uuid);

    if ($link === null) {
      throw new NotFoundHttpException(Yii::t('crelish', 'Short link not found.'));
    }

    return $link;
  }
}
```

- [ ] **Step 3: Write the list view**

`views/short-link/index.twig`:

```twig
{{ use('/yii/widgets/LinkPager') }}

<div class="u-window-box-medium">
  <form method="get" action="{{ url('crelish/short-link/index') }}" class="mb-3" style="max-width: 16rem">
    <select name="cr_status_filter" class="form-select" onchange="this.form.submit()">
      <option value="">{{ t('crelish', 'All statuses') }}</option>
      {% for key, label in statuses %}
        <option value="{{ key }}"{% if key == status %} selected{% endif %}>{{ label }}</option>
      {% endfor %}
    </select>
  </form>

  <table class="table table-striped table-hover">
    <thead>
      <tr>
        <th>{{ t('crelish', 'Title') }}</th>
        <th>{{ t('crelish', 'Short URL') }}</th>
        <th>{{ t('crelish', 'Target') }}</th>
        <th>{{ t('crelish', 'Status') }}</th>
        <th>{{ t('crelish', 'Scans (30 days)') }}</th>
        <th>{{ t('crelish', 'Clicks (30 days)') }}</th>
        <th>{{ t('crelish', 'Last hit') }}</th>
      </tr>
    </thead>
    <tbody>
      {% for link in dataProvider.models %}
        {% if rows[link.uuid] is defined %}
          {% set row = rows[link.uuid] %}
          <tr style="cursor: pointer" onclick="location.href='{{ url('crelish/short-link/update', {'uuid': link.uuid}) }}'">
            <td>{{ link.systitle }}</td>
            <td>
              <code>{{ link.getShortUrl() }}</code>
              <button type="button" class="c-button" title="{{ t('crelish', 'Copy') }}" data-copy="{{ link.getShortUrl() }}"
                      onclick="event.stopPropagation(); navigator.clipboard.writeText(this.dataset.copy)">
                <i class="fa-sharp fa-regular fa-copy"></i>
              </button>
            </td>
            <td>{{ row.target }}</td>
            <td><span class="badge text-bg-{{ row.badge }}">{{ statuses[row.status] }}</span></td>
            <td>{{ row.stats.scan }}</td>
            <td>{{ row.stats.click }}</td>
            <td>{{ row.stats.last ? row.stats.last|date('d.m.Y H:i') : '–' }}</td>
          </tr>
        {% endif %}
      {% else %}
        <tr><td colspan="7">{{ t('crelish', 'No short links yet.') }}</td></tr>
      {% endfor %}
    </tbody>
  </table>

  {{ link_pager_widget({'pagination': dataProvider.pagination}) | raw }}
</div>
```

- [ ] **Step 4: Write the edit view (link and target blocks)**

`views/short-link/edit.twig`:

```twig
{% import _self as f %}

{% macro input(link, name, label, opts) %}
  {% set error = opts.error ?? name %}
  <div class="mb-3">
    <label class="form-label" for="sl-{{ name }}">{{ label }}</label>
    <input type="{{ opts.type ?? 'text' }}" id="sl-{{ name }}" name="ShortLink[{{ name }}]"
           class="form-control{% if link.hasErrors(error) %} is-invalid{% endif %}"
           value="{{ opts.value ?? attribute(link, name) }}" {{ (opts.extra ?? '')|raw }}>
    {% if opts.help is defined %}<div class="form-text">{{ opts.help }}</div>{% endif %}
    {% if link.hasErrors(error) %}<div class="invalid-feedback">{{ link.getFirstError(error) }}</div>{% endif %}
  </div>
{% endmacro %}

{% set isNew = link.isNewRecord %}

<div class="u-window-box-medium shortlink-edit">
  {% if not isNew %}
    <ul class="nav nav-tabs mb-4" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sl-tab-link" type="button">{{ t('crelish', 'Link') }}</button></li>
      <li class="nav-item"><button class="nav-link" id="sl-stats-tab" data-bs-toggle="tab" data-bs-target="#sl-tab-stats" type="button">{{ t('crelish', 'Statistics') }}</button></li>
    </ul>
  {% endif %}

  <div class="tab-content">
    <div class="tab-pane fade show active" id="sl-tab-link">
      <form id="content-form" method="post">
        <input type="hidden" name="{{ csrfParam }}" value="{{ csrfToken }}">
        <input type="hidden" id="save_n_return" name="save_n_return" value="0">

        <h4>{{ t('crelish', 'Link') }}</h4>
        <div class="row">
          <div class="col-md-8">{{ f.input(link, 'systitle', t('crelish', 'Title')) }}</div>
          <div class="col-md-4">
            {{ f.input(link, 'code', t('crelish', 'Code'), {'help': (link.isNewRecord ? '' : link.getShortUrl()), 'extra': 'autocomplete="off" spellcheck="false"'}) }}
          </div>
        </div>
        <div class="row">
          <div class="col-md-4 mb-3">
            <label class="form-label" for="sl-state">{{ t('crelish', 'State') }}</label>
            <select id="sl-state" name="ShortLink[state]" class="form-select">
              {% for value, label in {2: t('crelish', 'Online'), 1: t('crelish', 'Draft'), 0: t('crelish', 'Offline'), 3: t('crelish', 'Archived')} %}
                <option value="{{ value }}"{% if link.state == value %} selected{% endif %}>{{ label }}</option>
              {% endfor %}
            </select>
          </div>
          <div class="col-md-4">{{ f.input(link, 'validFromInput', t('crelish', 'Valid from'), {'type': 'datetime-local', 'error': 'valid_from'}) }}</div>
          <div class="col-md-4">{{ f.input(link, 'validUntilInput', t('crelish', 'Valid until'), {'type': 'datetime-local', 'error': 'valid_until'}) }}</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="sl-note">{{ t('crelish', 'Internal note') }}</label>
          <textarea id="sl-note" name="ShortLink[note]" class="form-control" rows="2">{{ link.note }}</textarea>
        </div>

        <h4 class="mt-4">{{ t('crelish', 'Target') }}</h4>
        <div class="mb-3">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="ShortLink[target_type]" id="sl-target-content" value="content"{% if link.target_type == 'content' %} checked{% endif %}>
            <label class="form-check-label" for="sl-target-content">{{ t('crelish', 'Crelish content') }}</label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="ShortLink[target_type]" id="sl-target-url" value="url"{% if link.target_type == 'url' %} checked{% endif %}>
            <label class="form-check-label" for="sl-target-url">{{ t('crelish', 'External URL') }}</label>
          </div>
        </div>

        <div id="sl-target-content-fields"{% if link.target_type != 'content' %} hidden{% endif %}>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label" for="sl-target_ctype">{{ t('crelish', 'Content type') }}</label>
              <select id="sl-target_ctype" name="ShortLink[target_ctype]" class="form-select{% if link.hasErrors('target_ctype') %} is-invalid{% endif %}">
                <option value="">–</option>
                {% for ctype in targetTypes %}
                  <option value="{{ ctype }}"{% if ctype == link.target_ctype %} selected{% endif %}>{{ ctype }}</option>
                {% endfor %}
              </select>
              {% if link.hasErrors('target_ctype') %}<div class="invalid-feedback">{{ link.getFirstError('target_ctype') }}</div>{% endif %}
            </div>
            <div class="col-md-5 mb-3 position-relative">
              <label class="form-label" for="sl-target-search">{{ t('crelish', 'Record') }}</label>
              <input type="search" id="sl-target-search" class="form-control{% if link.hasErrors('target_uuid') %} is-invalid{% endif %}"
                     value="{{ targetTitle }}" autocomplete="off" placeholder="{{ t('crelish', 'Search by title…') }}">
              <input type="hidden" id="sl-target_uuid" name="ShortLink[target_uuid]" value="{{ link.target_uuid }}">
              <div id="sl-target-results" class="list-group position-absolute w-100" style="z-index: 10"></div>
              {% if link.hasErrors('target_uuid') %}<div class="invalid-feedback">{{ link.getFirstError('target_uuid') }}</div>{% endif %}
            </div>
            <div class="col-md-3 mb-3">
              <label class="form-label" for="sl-target_language">{{ t('crelish', 'Language') }}</label>
              <select id="sl-target_language" name="ShortLink[target_language]" class="form-select">
                <option value="">{{ t('crelish', 'Visitor language') }}</option>
                {% for language in languages %}
                  <option value="{{ language }}"{% if language == link.target_language %} selected{% endif %}>{{ language }}</option>
                {% endfor %}
              </select>
            </div>
          </div>
        </div>

        <div id="sl-target-url-fields"{% if link.target_type != 'url' %} hidden{% endif %}>
          {{ f.input(link, 'target_url', t('crelish', 'Target URL'), {'type': 'url', 'extra': 'placeholder="https://"'}) }}
        </div>

        {{ f.input(link, 'fallback_url', t('crelish', 'Fallback URL'), {
          'type': 'url',
          'extra': 'placeholder="' ~ siteFallback|e('html_attr') ~ '"',
          'help': t('crelish', 'Used when the link is offline, outside its validity window, or its target no longer exists.')
        }) }}

        {% if resolvesTo %}
          <p class="form-text">
            {{ t('crelish', 'Currently resolves to') }}:
            <a href="{{ resolvesTo.url }}" target="_blank" rel="noopener">{{ resolvesTo.url }}</a>
            {% if resolvesTo.isFallback() %}<span class="badge text-bg-warning">{{ t('crelish', 'fallback') }}: {{ resolvesTo.reason }}</span>{% endif %}
          </p>
        {% endif %}

        {# QR block: filled in Task 11 #}
      </form>
    </div>

    {# Statistics tab: filled in Task 11 #}
  </div>
</div>

<script>
(function () {
  const form = document.getElementById('content-form');

  const toggleTarget = () => {
    const type = form.querySelector('input[name="ShortLink[target_type]"]:checked')?.value;
    document.getElementById('sl-target-content-fields').hidden = type !== 'content';
    document.getElementById('sl-target-url-fields').hidden = type !== 'url';
  };
  form.querySelectorAll('input[name="ShortLink[target_type]"]').forEach(radio => radio.addEventListener('change', toggleTarget));

  const ctype = document.getElementById('sl-target_ctype');
  const search = document.getElementById('sl-target-search');
  const uuid = document.getElementById('sl-target_uuid');
  const results = document.getElementById('sl-target-results');
  const targetsUrl = {{ url('crelish/short-link/targets')|json_encode|raw }};
  let timer;

  ctype.addEventListener('change', () => {
    uuid.value = '';
    search.value = '';
    results.replaceChildren();
  });

  search.addEventListener('input', () => {
    uuid.value = '';
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const q = search.value.trim();
      if (!ctype.value || q.length < 2) {
        results.replaceChildren();
        return;
      }
      const url = new URL(targetsUrl, location.href);
      url.searchParams.set('ctype', ctype.value);
      url.searchParams.set('q', q);
      const response = await fetch(url, {headers: {'Accept': 'application/json'}});
      const items = response.ok ? await response.json() : [];
      results.replaceChildren(...items.map(item => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'list-group-item list-group-item-action';
        button.textContent = item.title;
        button.addEventListener('click', () => {
          uuid.value = item.uuid;
          search.value = item.title;
          results.replaceChildren();
        });
        return button;
      }));
    }, 250);
  });
})();
</script>
```

The two `{# … filled in Task 11 #}` comments mark where Task 11 inserts markup. Plain markers are used instead of `{% block %}` because macros imported with `{% import _self %}` are not reliably visible inside blocks.

- [ ] **Step 5: Run the automated tests**

Run: `for t in tests/ShortLink*Test.php tests/Qr*Test.php; do php "$t" > /dev/null || echo "FAILED: $t"; done`
Expected: no `FAILED` lines.

- [ ] **Step 6: Verify in the browser**

Use the `run` skill (or ask the user to check) against the local forum-holzbau site from Task 9, logged in as an admin:

1. The sidebar shows "Short Links" between Assets and User.
2. `/crelish/short-link/index` shows the empty state; the header has a "+" button.
3. "+" opens the form with a 6-character code pre-filled and state Online.
4. Save with an external URL `javascript:alert(1)` → a field error, nothing saved.
5. Save with `https://www.example.com/` → redirected to the edit view with "Short link saved." and a "Currently resolves to" line.
6. Switch to "Crelish content", pick `news`, type two letters of a news title, pick a result, save → "Currently resolves to" shows `/de/news/<uuid>/<slug>`, and that URL opens the article.
7. Save and return (second save button) → back on the list; the row shows the status badge, short URL and copy button.
8. The status filter "Online" keeps the row; "Draft" hides it.
9. Delete (trash button in the header, confirm) → back on the list with "Short link deleted."
10. The browser console shows no errors on these pages.

- [ ] **Step 7: Commit**

```bash
git add controllers/ShortLinkController.php views/short-link config/sidebar.json components/CrelishSidebarManager.php
git commit -m "feat(shortlinks): admin list, edit form and target picker"
```

---

### Task 11: Admin QR panel and statistics tab

**Files:**
- Modify: `controllers/ShortLinkController.php` (replace `qrAndStatsParams()`, add `actionQrPreview`, `actionQrDownload`, `logoWidget`)
- Modify: `views/short-link/edit.twig` (fill the `qr` and `stats` blocks, add JS)

**Interfaces:**
- Consumes: `QrBundleService` (Task 7), `ShortLinkStats::forLink` (Task 8), `CrelishAnalyticsPeriod::resolve(?string $period, ?string $start, ?string $end): array{0:string,1:string,2:string}`, `CrelishAnalyticsPeriodPicker::widget([...])`, which dispatches `crelish:periodchange` with `detail` `{period, start_date?, end_date?}`
- Produces: routes `crelish/short-link/qr-preview?uuid=&variant=plain|logo&size=&color=&quiet=` (SVG) and `crelish/short-link/qr-download?uuid=[&file=]` (ZIP or single file)

- [ ] **Step 1: Replace `qrAndStatsParams()` and add the QR actions**

In `controllers/ShortLinkController.php`, replace the placeholder `qrAndStatsParams()` with:

```php
  protected function qrAndStatsParams(ShortLink $link): array
  {
    if ($link->isNewRecord) {
      return ['hasLogo' => false, 'qrFiles' => [], 'logoWidget' => '', 'stats' => null, 'periodPicker' => '', 'logoMinMm' => QrBundleService::LOGO_MIN_MM];
    }

    $request = Yii::$app->request;
    [$startDate, $endDate, $period] = CrelishAnalyticsPeriod::resolve(
      $request->get('period', CrelishAnalyticsPeriod::FALLBACK),
      $request->get('start_date'),
      $request->get('end_date')
    );
    $hasLogo = QrBundleService::logoPathFor($link) !== null;

    $this->view->registerJsFile('https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js', ['position' => \yii\web\View::POS_HEAD]);

    return [
      'hasLogo' => $hasLogo,
      'qrFiles' => QrBundleService::fileNames($link, $hasLogo),
      'logoWidget' => $this->logoWidget($link),
      'logoMinMm' => QrBundleService::LOGO_MIN_MM,
      'stats' => (new ShortLinkStats())->forLink($link->uuid, $startDate, $endDate),
      'periodPicker' => CrelishAnalyticsPeriodPicker::widget(['period' => $period, 'startDate' => $startDate, 'endDate' => $endDate]),
    ];
  }

  /**
   * Live SVG preview; size, colour and quiet zone can be overridden from the unsaved form
   */
  public function actionQrPreview(string $uuid, string $variant = 'plain'): Response
  {
    $link = $this->findLink($uuid);
    $request = Yii::$app->request;
    $overrides = array_filter([
      'qr_size_mm' => $request->get('size'),
      'qr_color' => $request->get('color'),
      'qr_quiet_zone' => $request->get('quiet'),
    ], fn($value) => $value !== null && $value !== '');

    $link->setAttributes($overrides);
    if ($overrides !== [] && !$link->validate(array_keys($overrides))) {
      $link->refresh();
    }

    $response = Yii::$app->response;
    $response->format = Response::FORMAT_RAW;
    $response->headers->set('Content-Type', 'image/svg+xml');
    $response->headers->set('Cache-Control', 'no-store');

    try {
      $response->data = QrBundleService::forLink($link)->renderer($link, $variant)->svg();
    } catch (\RuntimeException $e) {
      $response->statusCode = 404;
      $response->data = '';
    }

    return $response;
  }

  public function actionQrDownload(string $uuid, ?string $file = null): Response
  {
    $link = $this->findLink($uuid);
    $bundle = QrBundleService::forLink($link);
    $mimeTypes = ['svg' => 'image/svg+xml', 'eps' => 'application/postscript', 'pdf' => 'application/pdf', 'png' => 'image/png'];

    try {
      if ($file === null) {
        $path = $bundle->zip($link);

        if ($bundle->logoError !== null) {
          Yii::$app->session->setFlash('warning', Yii::t('crelish', 'The logo variant was skipped: {error}', ['error' => $bundle->logoError]));
        }

        $response = Yii::$app->response->sendFile($path, $link->code . '-qr.zip', ['mimeType' => 'application/zip']);
        $response->on(Response::EVENT_AFTER_SEND, static function () use ($path) {
          @unlink($path);
        });

        return $response;
      }

      $files = $bundle->files($link);
      $extension = pathinfo($file, PATHINFO_EXTENSION);

      if (!isset($files[$file], $mimeTypes[$extension])) {
        throw new NotFoundHttpException();
      }

      return Yii::$app->response->sendContentAsFile($files[$file], str_replace('/', '-', $file), ['mimeType' => $mimeTypes[$extension]]);
    } catch (\RuntimeException $e) {
      Yii::error("QR export failed for short link {$link->uuid}: " . $e->getMessage(), 'shortlink');
      Yii::$app->session->setFlash('error', Yii::t('crelish', 'The QR code could not be generated: {error}', ['error' => $e->getMessage()]));

      return $this->redirect(['update', 'uuid' => $link->uuid]);
    }
  }

  private function logoWidget(ShortLink $link): string
  {
    return AssetConnector::widget([
      'formKey' => 'logo_asset_uuid',
      'field' => (object)[
        'key' => 'logo_asset_uuid',
        'label' => Yii::t('crelish', 'QR logo'),
        'config' => (object)[],
        'rules' => [],
      ],
      'data' => $link->logo_asset_uuid,
      'model' => $link,
    ]);
  }
```

- [ ] **Step 2: Fill in the QR block**

In `views/short-link/edit.twig`, replace the line `{# QR block: filled in Task 11 #}` with:

```twig
          {% if not isNew %}
            <h4 class="mt-4">{{ t('crelish', 'QR code') }}</h4>
            <div class="row">
              <div class="col-md-3">{{ f.input(link, 'qr_size_mm', t('crelish', 'Print size (mm, incl. quiet zone)'), {'type': 'number', 'extra': 'min="10" max="500" data-qr-setting="size"'}) }}</div>
              <div class="col-md-3">{{ f.input(link, 'qr_color', t('crelish', 'Colour'), {'type': 'color', 'extra': 'data-qr-setting="color"'}) }}</div>
              <div class="col-md-3">{{ f.input(link, 'qr_quiet_zone', t('crelish', 'Quiet zone (modules)'), {'type': 'number', 'extra': 'min="4" max="20" data-qr-setting="quiet"'}) }}</div>
            </div>
            <div class="mb-3">
              {{ logoWidget|raw }}
              <div class="form-text">{{ t('crelish', 'Optional. Overrides the site-wide logo. PNG or JPEG, ideally square.') }}</div>
            </div>
            <div class="d-flex gap-4 align-items-start mb-3">
              <figure class="text-center">
                <img id="sl-qr-plain" src="{{ url('crelish/short-link/qr-preview', {'uuid': link.uuid, 'variant': 'plain'}) }}" width="200" height="200" alt="">
                <figcaption>{{ t('crelish', 'Plain') }}</figcaption>
              </figure>
              {% if hasLogo %}
                <figure class="text-center">
                  <img id="sl-qr-logo" src="{{ url('crelish/short-link/qr-preview', {'uuid': link.uuid, 'variant': 'logo'}) }}" width="200" height="200" alt="">
                  <figcaption>{{ t('crelish', 'With logo') }}</figcaption>
                </figure>
              {% endif %}
            </div>
            <div id="sl-qr-logo-warning" class="alert alert-warning"{% if not hasLogo or link.qr_size_mm >= logoMinMm %} hidden{% endif %}>
              {{ t('crelish', 'The logo variant is hard to scan below {mm} mm. Use the plain variant for small print.', {'mm': logoMinMm}) }}
            </div>
            <p>
              <a class="c-button c-button--success" href="{{ url('crelish/short-link/qr-download', {'uuid': link.uuid}) }}">
                <i class="fa-sharp fa-regular fa-download"></i> {{ t('crelish', 'Download ZIP') }}
              </a>
              {% for file in qrFiles %}
                <a class="ms-2" href="{{ url('crelish/short-link/qr-download', {'uuid': link.uuid, 'file': file}) }}">{{ file }}</a>
              {% endfor %}
            </p>
            <p class="form-text">{{ t('crelish', 'Downloads use the saved settings. Save after changing them.') }}</p>
          {% endif %}
```

- [ ] **Step 3: Fill in the statistics tab**

Replace the line `{# Statistics tab: filled in Task 11 #}` with:

```twig
      {% if not isNew %}
        <div class="tab-pane fade" id="sl-tab-stats">
          <div class="mb-3" style="max-width: 20rem">{{ periodPicker|raw }}</div>
          <div class="row text-center mb-4">
            {% for key, label in {'scan': t('crelish', 'QR scans'), 'click': t('crelish', 'Link clicks'), 'fallback': t('crelish', 'Fallback hits')} %}
              <div class="col"><div class="fs-2">{{ stats.totals[key] }}</div><div>{{ label }}</div></div>
            {% endfor %}
            <div class="col"><div class="fs-2">{{ stats.uniqueSessions }}</div><div>{{ t('crelish', 'Unique sessions (per day, summed)') }}</div></div>
          </div>
          {% if stats.totals.fallback > 0 and resolvesTo and resolvesTo.isFallback() %}
            <div class="alert alert-warning">{{ t('crelish', 'Visitors currently reach the fallback ({reason}).', {'reason': resolvesTo.reason}) }}</div>
          {% endif %}
          <canvas id="sl-stats-chart" height="120"
                  data-labels="{{ {'scan': t('crelish', 'QR scans'), 'click': t('crelish', 'Link clicks'), 'fallback': t('crelish', 'Fallback hits')}|json_encode|e('html_attr') }}"></canvas>
          <script type="application/json" id="sl-stats-data">{{ stats.days|json_encode(constant('JSON_HEX_TAG'))|raw }}</script>
        </div>
      {% endif %}
```

- [ ] **Step 4: Add the QR and statistics JS**

In `views/short-link/edit.twig`, inside the existing `<script>` IIFE, directly before the closing `})();`, add:

```js
  const previews = ['plain', 'logo'].map(variant => document.getElementById('sl-qr-' + variant)).filter(Boolean);
  const logoWarning = document.getElementById('sl-qr-logo-warning');
  const settings = form.querySelectorAll('[data-qr-setting]');
  const refreshPreview = () => {
    const values = {};
    settings.forEach(input => { values[input.dataset.qrSetting] = input.value; });
    previews.forEach(img => {
      const url = new URL(img.src);
      Object.entries(values).forEach(([key, value]) => url.searchParams.set(key, value));
      img.src = url;
    });
    if (logoWarning && document.getElementById('sl-qr-logo')) {
      logoWarning.hidden = Number(values.size) >= {{ logoMinMm ?? 25 }};
    }
  };
  settings.forEach(input => input.addEventListener('change', refreshPreview));

  const statsData = document.getElementById('sl-stats-data');
  const chartCanvas = document.getElementById('sl-stats-chart');
  if (statsData && chartCanvas && window.Chart) {
    const days = JSON.parse(statsData.textContent);
    const labels = JSON.parse(chartCanvas.dataset.labels);
    const dates = Object.keys(days);
    const colors = {scan: '#2f6f4f', click: '#8fb996', fallback: '#d9a441'};
    new Chart(chartCanvas, {
      type: 'bar',
      data: {
        labels: dates,
        datasets: Object.keys(colors).map(type => ({label: labels[type], data: dates.map(date => days[date][type]), backgroundColor: colors[type]})),
      },
      options: {responsive: true, scales: {x: {stacked: true}, y: {stacked: true, beginAtZero: true, ticks: {precision: 0}}}},
    });
  }

  document.addEventListener('crelish:periodchange', event => {
    const url = new URL(location.href);
    ['period', 'start_date', 'end_date'].forEach(key => url.searchParams.delete(key));
    Object.entries(event.detail).forEach(([key, value]) => url.searchParams.set(key, value));
    url.hash = 'stats';
    location.href = url;
  });

  if (location.hash === '#stats') {
    document.getElementById('sl-stats-tab')?.click();
  }
```

- [ ] **Step 5: Run the automated tests**

Run: `for t in tests/ShortLink*Test.php tests/Qr*Test.php; do php "$t" > /dev/null || echo "FAILED: $t"; done`
Expected: no `FAILED` lines.

- [ ] **Step 6: Verify in the browser**

On the local site, logged in as admin, with an existing link:

1. The edit view shows the QR block with a plain preview. (No logo preview while `qrLogo` is null.)
2. Changing the colour to green updates the preview without saving. Changing the quiet zone to 8 visibly widens the white border.
3. "Download ZIP" downloads `<code>-qr.zip` with `plain/` (4 files) and `README.txt`. Open the SVG, PDF and EPS (Preview/Illustrator): all are 30 mm wide. The PNG is 363 px at 307 dpi (`sips -g dpiWidth -g pixelWidth plain/<code>.png`).
4. Scan the plain PNG from the screen with a phone: the phone opens `…/GO/<CODE>/Q` and lands on the target. The statistics tab then shows 1 QR scan today.
5. Pick a square PNG through the logo asset field, save: a second preview "With logo" appears; set the size to 20 → the warning appears; set it to 30 → it disappears. The ZIP now also contains `logo/` (3 files); scan the logo PNG with a phone.
6. Choose a different period in the statistics tab → the page reloads on the statistics tab with that period.
7. No console errors.

- [ ] **Step 7: Commit**

```bash
git add controllers/ShortLinkController.php views/short-link/edit.twig
git commit -m "feat(shortlinks): QR preview, downloads and statistics tab in the admin"
```

---

### Task 12: Documentation

**Files:**
- Create: `docs/shortlinks.md`
- Modify: `docs/README.md`

- [ ] **Step 1: Write the docs**

`docs/shortlinks.md`:

````markdown
# Short Links

Campaign and print links with QR export and tracking: `https://example.com/go/<code>`.

## Enable

1. Run the migration: `./yii crelish-migrate`
2. Configure `params['crelish']['shortLinks']`:

```php
'shortLinks' => [
  'enabled'       => true,
  'prefix'        => 'go',          // /go/<code>
  'shortHost'     => null,          // e.g. 'fhb.link' (needs DNS + vhost to the same docroot)
  'siteUrl'       => null,          // absolute main-site URL; required with shortHost
  'fallbackUrl'   => null,          // null = home page
  'detailPages'   => ['news' => 'news'],   // ctype => listing page slug
  'qrLogo'        => '@webroot/img/qr-signet.png', // PNG/JPEG, ideally square
  'reservedCodes' => [],
],
```

"Short Links" then appears in the admin sidebar.

## Targets

A link points to an external URL or to a crelish record. Records are resolved on every hit, so a link
survives slug and title changes:

1. A model implementing `giantbits\crelish\components\shortlinks\ShortLinkTargetInterface` returns its own URL.
2. A ctype listed in `detailPages` resolves to `urlFromSlug('<page>')/<uuid>/<slugified systitle>`,
   the convention used by crelish list widgets.
3. Records with a `slug` attribute (pages) resolve to `urlFromSlug($slug)`.

A record that is missing, not online (`state != 2`) or outside its `from`/`to` window counts as broken.

## Dead links

Printed codes never end on a 404. Offline, draft, archived, not-yet-valid, expired and broken links redirect
to the link's fallback URL, then to `fallbackUrl`, then to the home page. Broken targets log a warning
(category `shortlink`) once per link and day.

## Tracking

Each hit is an analytics element event: `element_type = 'shortlink'`, `type` is `scan` (via the QR code's
`/q` URL), `click` or `fallback`. The redirect creates the analytics session itself, so the nightly
aggregation, bot filtering and retention treat short links like any other element.
With a separate `shortHost` the session cookie belongs to that host, so a scan and the following page
view are not linked into one session.

## QR codes

The QR code contains the short URL in uppercase (`HTTPS://EXAMPLE.COM/GO/IHF26/Q`), which uses the QR
alphanumeric mode and gives a smaller code. The ZIP bundle contains:

- `plain/` SVG, EPS, PDF (vector) and PNG (>= 300 dpi), error correction M, readable from 15 mm
- `logo/` SVG, PDF and PNG with the logo, error correction H, only for 25 mm and larger (EPS cannot embed images)
- `README.txt`

The size in mm includes the quiet zone.
````

In `docs/README.md`, after the line `- [Click Tracking](./click-tracking.md) - Link and element click tracking`, add:

```markdown
- [Short Links](./shortlinks.md) - Campaign short links with QR export and tracking
```

- [ ] **Step 2: Commit**

```bash
git add docs/shortlinks.md docs/README.md
git commit -m "docs(shortlinks): configuration, targets, tracking and QR export"
```

---

### Task 13: Final verification and hand-off

- [ ] **Step 1: Run every test**

From the crelish root:

```bash
for t in tests/*.php; do echo "== $t"; php "$t" | tail -1; done
```

Expected: each short link and QR test ends with `N passed, 0 failed`, and the four existing tests (`ClickTrackingSecurityTest`, `DynamicModelUuidTest`, `FormTabsValidationTest`, `RelationSelectDanglingTest`) pass as before.

- [ ] **Step 2: Static analysis**

Run: `vendor/bin/phpstan analyse --no-progress components/shortlinks models/ShortLink.php controllers/ShortLinkController.php controllers/ShortLinkRedirectController.php`
Expected: no errors at the configured level (fix anything reported in these files; leave pre-existing findings elsewhere alone).

- [ ] **Step 3: Report to the user**

Report the branch state in both repositories and stop. Do **not** merge, tag, release, push or deploy. Those steps need the user's go-ahead:
- crelish: git-flow release `0.22.0` (bump `"version"` in `composer.json`), then push `main`, `develop` and the tag so Packagist picks it up.
- forum-holzbau: raise `giantbits/yii2-crelish` to `^0.22` in `composer.json` (`^0.21` never pulls 0.22), commit, deploy, then run `./yii crelish-migrate` on production.
- A square signet PNG for `qrLogo` (the theme only has wide wordmarks).
- Before the first real campaign: print plain and logo variants at 15, 20, 25 and 30 mm and scan them with iOS and Android camera apps.
