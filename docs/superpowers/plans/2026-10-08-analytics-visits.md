# Analytics Visits and Verifying Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Correct "unique" figures (as visits counted per day) in company reports and admin statistics, and a cleanup that verifies each day against raw data, repairing before it deletes.

**Architecture:** A new daily aggregate table `analytics_visits_daily` is filled by the nightly `daily` run next to the page and element aggregates. Aggregation is refactored into `aggregateDate($date, $parts, $repair)` with independent parts and a repair mode that only raises numbers. Pure logic (part selection, retention days, per-day decision) lives in small classes under `components/Analytics/` with SQLite/pure unit tests; the MySQL-specific SQL is tested against a scratch MariaDB/MySQL database. The read side reads visits through one `VisitsReader`.

**Tech Stack:** PHP 8.2+, Yii 2 (console + web), MariaDB 10.6 in production (non-strict `sql_mode`), Twig views with Chart.js, plain-PHP test scripts (`php tests/X.php`).

**Spec:** `docs/superpowers/specs/2026-10-08-analytics-visits-design.md`

## Global Constraints

- Release: crelish **0.25.0**.
- Meaning of the figure: **visits** = distinct sessions per day, summed over the days of a period. Never sum `unique_sessions` across pages, elements or event types for a total.
- Visit rows only: `source` ∈ {`pages`, `elements`}; `owner_uuid` `''` = whole site, otherwise the element rows' `page_uuid`; `event_type` `''` = any event.
- Stored numbers never shrink through repair: a day passes when every stored value ≥ its raw value; repair merges with `GREATEST(stored, recomputed)` and never deletes rows.
- Normal (nightly / explicit backfill) aggregation overwrites; visit rows of a day are DELETEd then INSERTed in one transaction.
- Bot filtering exactly as the existing aggregates: page views `is_bot = 0`; element views joined to `analytics_sessions` with `is_bot = 0`.
- Cleanup deletes whole days only: raw rows of day D are deleted only when D < today − `retentionDays`.
- A missing `analytics_visits_daily` table never fails `daily` or `cleanup`; the UI then shows "Besuche noch nicht erfasst" / "Visits not recorded yet".
- `--pagesOnly=1` (released in 0.24.2) keeps working as an alias for `--only=pages`.
- German UI strings: `Visits` → `Besuche`, `Visits (detail view)` → `Besuche (Detailansicht)`, `Logged-in users (detail view)` → `Angemeldete Nutzer (Detailansicht)`, `Visits counted from {date}` → `Besuche erfasst ab {date}`, `Visits not recorded yet` → `Besuche noch nicht erfasst`.
- Line endings: keep each file's existing ones. CRLF: `controllers/AnalyticsAggregatedController.php`, `controllers/CompanyAnalyticsController.php`, all `views/analytics-aggregated/*.twig`, `views/company-analytics/*.twig`. LF: everything else touched here, and all new files. After editing a CRLF file, `git diff --stat` must show only the lines you changed.
- Production runs MariaDB 10.6 with `sql_mode = NO_ENGINE_SUBSTITUTION`; repeated named placeholders in one statement are not used (one statement per query shape instead).

## Review Focus

1. **Element views with a missing type** (`type` NULL or `''`): counted in the "any event" visit rows, never written as a per-type row that would collide with `''`. Pinned in Task 3.
2. **Element views without an owner** (`page_uuid = ''`): counted in the site rows only; must not create an owner row `''` colliding with the site row. Pinned in Task 3.
3. **The same company UUID in different letter case** (both spellings exist in production data): one owner row per day, found by a lower-case lookup. Pinned in Task 3.
4. **A day holding only bot traffic** in the deletion range: passes verification (nothing reportable) instead of being kept forever. Pinned in Task 5.
5. **A period that starts before visits were first recorded:** the figure covers only the recorded days and says from when; the old summed value is never shown. Pinned in Task 6.

---

### Task 1: Test harness and `--only` part selection

**Files:**
- Create: `tests/analytics/bootstrap.php`
- Create: `tests/analytics/mysql.php`
- Create: `components/Analytics/AggregationParts.php`
- Test: `tests/AnalyticsAggregationPartsTest.php`

**Interfaces:**
- Produces: `check(string $name, mixed $expected, mixed $actual): void`, `checkThrows(string $name, callable $fn, string $class): void`, `analyticsDone(): never` (bootstrap.php); `analyticsMysqlApp(bool $withVisitsTable = true): \yii\console\Application`, `session(string $id, int $isBot = 0, ?int $userId = null, string $createdAt = '2025-01-01 00:00:00'): void`, `pageView(string $date, string $time, string $pageUuid, string $url, ?string $sessionId, int $isBot = 0, ?int $userId = null): void`, `elementView(string $date, string $time, string $elementUuid, ?string $type, string $pageUuid, string $sessionId, ?int $userId = null, string $elementType = 'job'): void`, `daysAgo(int $n): string`, `rows(string $sql, array $params = []): array`, `scalar(string $sql, array $params = []): mixed` (mysql.php); `AggregationParts::PAGES|ELEMENTS|VISITS`, `AggregationParts::ALL`, `AggregationParts::resolve(?string $only, bool $pagesOnly = false): array` (throws `\InvalidArgumentException`).

- [ ] **Step 1: Write the shared test bootstrap**

`tests/analytics/bootstrap.php`:

```php
<?php

/**
 * Shared helpers for the analytics tests: autoloading and the check() reporter.
 * Pure-logic tests need nothing else; database tests also require mysql.php
 * or sqlite.php from this directory.
 */

declare(strict_types=1);

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'test');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

Yii::setAlias('@giantbits/crelish', dirname(__DIR__, 2));

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

function checkThrows(string $name, callable $fn, string $class): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        check($name, $class, get_class($e));
        return;
    }

    check($name, $class, 'no exception');
}

function analyticsDone(): never
{
    global $failures, $passed;

    echo "\n$passed passed, $failures failed\n";
    exit($failures === 0 ? 0 : 1);
}
```

- [ ] **Step 2: Write the MySQL harness**

`tests/analytics/mysql.php` (the raw and daily tables in their production shape, taken from `SHOW CREATE TABLE` on forum-holzkarriere; the migration for the visits table is used once it exists, from Task 3 on):

```php
<?php

/**
 * MySQL/MariaDB for the aggregation tests: their SQL (ON DUPLICATE KEY UPDATE,
 * GREATEST, VALUES) does not run on SQLite.
 *
 *   CRELISH_TEST_MYSQL_DSN       e.g. mysql:host=127.0.0.1;port=3306 (no dbname)
 *   CRELISH_TEST_MYSQL_USER      default root
 *   CRELISH_TEST_MYSQL_PASSWORD  default empty
 *
 * Every analyticsMysqlApp() call drops and recreates the database
 * crelish_analytics_test. Without CRELISH_TEST_MYSQL_DSN the test prints
 * SKIPPED and exits 0.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use giantbits\crelish\migrations\m261008_120000_create_analytics_visits_daily;
use yii\db\Connection;
use yii\db\Query;

const ANALYTICS_TEST_DB = 'crelish_analytics_test';

function analyticsMysqlApp(bool $withVisitsTable = true): \yii\console\Application
{
    $dsn = getenv('CRELISH_TEST_MYSQL_DSN');
    if (!$dsn) {
        echo "SKIPPED: set CRELISH_TEST_MYSQL_DSN, CRELISH_TEST_MYSQL_USER and CRELISH_TEST_MYSQL_PASSWORD\n";
        exit(0);
    }

    $user = getenv('CRELISH_TEST_MYSQL_USER') ?: 'root';
    $password = getenv('CRELISH_TEST_MYSQL_PASSWORD') ?: '';

    $pdo = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('DROP DATABASE IF EXISTS ' . ANALYTICS_TEST_DB);
    $pdo->exec('CREATE DATABASE ' . ANALYTICS_TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $app = new \yii\console\Application([
        'id' => 'analytics-test',
        'basePath' => dirname(__DIR__, 2),
        'components' => [
            'db' => [
                'class' => Connection::class,
                'dsn' => $dsn . ';dbname=' . ANALYTICS_TEST_DB,
                'username' => $user,
                'password' => $password,
                'charset' => 'utf8mb4',
                // Production runs non-strict (NO_ENGINE_SUBSTITUTION)
                'on afterOpen' => static function ($event): void {
                    $event->sender->pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
                },
            ],
        ],
    ]);

    analyticsMysqlSchema($app->db, $withVisitsTable);

    return $app;
}

function analyticsMysqlSchema(Connection $db, bool $withVisitsTable): void
{
    $db->createCommand("CREATE TABLE analytics_sessions (
        session_id varchar(100) NOT NULL,
        user_id int(11) DEFAULT NULL,
        ip_address varchar(45) DEFAULT NULL,
        user_agent varchar(255) DEFAULT NULL,
        is_bot tinyint(1) DEFAULT 0,
        bot_score tinyint(3) unsigned DEFAULT NULL,
        bot_reason varchar(255) DEFAULT NULL,
        first_page_uuid varchar(36) DEFAULT NULL,
        first_url varchar(255) DEFAULT NULL,
        created_at timestamp NULL DEFAULT current_timestamp(),
        last_activity timestamp NULL DEFAULT current_timestamp(),
        total_pages int(11) DEFAULT 1,
        PRIMARY KEY (session_id),
        KEY created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")->execute();

    $db->createCommand("CREATE TABLE analytics_page_views (
        id int(11) NOT NULL AUTO_INCREMENT,
        page_uuid varchar(36) NOT NULL,
        page_type varchar(50) NOT NULL,
        url varchar(255) NOT NULL,
        referer varchar(255) DEFAULT NULL,
        session_id varchar(100) DEFAULT NULL,
        user_id int(11) DEFAULT NULL,
        user_agent varchar(255) DEFAULT NULL,
        ip_address varchar(45) DEFAULT NULL,
        is_bot tinyint(1) DEFAULT 0,
        created_at timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        KEY idx_page_views_aggregation (created_at, page_uuid, is_bot)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")->execute();

    $db->createCommand("CREATE TABLE analytics_element_views (
        id int(11) NOT NULL AUTO_INCREMENT,
        element_uuid varchar(36) NOT NULL,
        element_type varchar(50) NOT NULL,
        page_uuid varchar(36) NOT NULL,
        session_id varchar(100) DEFAULT NULL,
        user_id int(11) DEFAULT NULL,
        type varchar(255) DEFAULT NULL,
        created_at timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        KEY idx_element_views_aggregation (created_at, element_uuid, element_type, type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")->execute();

    $db->createCommand("CREATE TABLE analytics_page_daily (
        id int(11) NOT NULL AUTO_INCREMENT,
        date date NOT NULL,
        page_uuid varchar(36) NOT NULL,
        page_url varchar(255) NOT NULL,
        total_views int(11) DEFAULT 0,
        unique_sessions int(11) DEFAULT 0,
        unique_users int(11) DEFAULT 0,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (id),
        UNIQUE KEY `idx-page_daily-unique` (date, page_uuid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();

    $db->createCommand("CREATE TABLE analytics_element_daily (
        id int(11) NOT NULL AUTO_INCREMENT,
        date date NOT NULL,
        element_uuid varchar(36) NOT NULL,
        element_type varchar(50) NOT NULL,
        page_uuid varchar(36) DEFAULT NULL,
        event_type varchar(20) NOT NULL,
        total_views int(11) DEFAULT 0,
        unique_sessions int(11) DEFAULT 0,
        unique_users int(11) DEFAULT 0,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (id),
        UNIQUE KEY `idx-element_daily-unique` (date, element_uuid, element_type, event_type, page_uuid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")->execute();

    if ($withVisitsTable && class_exists(m261008_120000_create_analytics_visits_daily::class)) {
        (new m261008_120000_create_analytics_visits_daily(['db' => $db, 'compact' => true]))->up();
    }
}

function session(string $id, int $isBot = 0, ?int $userId = null, string $createdAt = '2025-01-01 00:00:00'): void
{
    Yii::$app->db->createCommand()->insert('analytics_sessions', [
        'session_id' => $id, 'is_bot' => $isBot, 'user_id' => $userId, 'created_at' => $createdAt,
    ])->execute();
}

function pageView(string $date, string $time, string $pageUuid, string $url, ?string $sessionId, int $isBot = 0, ?int $userId = null): void
{
    Yii::$app->db->createCommand()->insert('analytics_page_views', [
        'page_uuid' => $pageUuid, 'page_type' => 'page', 'url' => $url, 'session_id' => $sessionId,
        'user_id' => $userId, 'is_bot' => $isBot, 'created_at' => "$date $time",
    ])->execute();
}

function elementView(string $date, string $time, string $elementUuid, ?string $type, string $pageUuid, string $sessionId, ?int $userId = null, string $elementType = 'job'): void
{
    Yii::$app->db->createCommand()->insert('analytics_element_views', [
        'element_uuid' => $elementUuid, 'element_type' => $elementType, 'page_uuid' => $pageUuid,
        'session_id' => $sessionId, 'user_id' => $userId, 'type' => $type, 'created_at' => "$date $time",
    ])->execute();
}

function daysAgo(int $n): string
{
    return date('Y-m-d', strtotime("-{$n} days"));
}

function rows(string $sql, array $params = []): array
{
    return Yii::$app->db->createCommand($sql, $params)->queryAll();
}

function scalar(string $sql, array $params = []): mixed
{
    return Yii::$app->db->createCommand($sql, $params)->queryScalar();
}
```

- [ ] **Step 3: Write the failing test for part selection**

`tests/AnalyticsAggregationPartsTest.php`:

```php
<?php

/**
 * --only / --pagesOnly selection of aggregation parts.
 *
 * Run with:  php tests/AnalyticsAggregationPartsTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/bootstrap.php';

use giantbits\crelish\components\Analytics\AggregationParts;

echo "Selection\n";
check('nothing given means all parts', ['pages', 'elements', 'visits'], AggregationParts::resolve(null));
check('empty string means all parts', ['pages', 'elements', 'visits'], AggregationParts::resolve(''));
check('one part', ['visits'], AggregationParts::resolve('visits'));
check('canonical order, spaces and case ignored', ['pages', 'visits'], AggregationParts::resolve(' Visits , pages '));
check('duplicates collapse', ['elements'], AggregationParts::resolve('elements,elements'));
checkThrows('unknown part is rejected', fn() => AggregationParts::resolve('pages,sessions'), \InvalidArgumentException::class);

echo "\n--pagesOnly alias (0.24.2)\n";
check('pagesOnly alone', ['pages'], AggregationParts::resolve(null, true));
check('pagesOnly with matching --only', ['pages'], AggregationParts::resolve('pages', true));
checkThrows('pagesOnly with a different --only', fn() => AggregationParts::resolve('visits', true), \InvalidArgumentException::class);

analyticsDone();
```

- [ ] **Step 4: Run it to verify it fails**

Run: `php tests/AnalyticsAggregationPartsTest.php`
Expected: fatal error `Class "giantbits\crelish\components\Analytics\AggregationParts" not found`.

- [ ] **Step 5: Implement `AggregationParts`**

`components/Analytics/AggregationParts.php`:

```php
<?php

namespace giantbits\crelish\components\Analytics;

/**
 * Which aggregates a daily/monthly/backfill run computes (--only).
 *
 * pages    analytics_page_daily (and page_monthly in `monthly`)
 * elements analytics_element_daily (and element_monthly in `monthly`)
 * visits   analytics_visits_daily (daily only; monthly visits are sums of days)
 */
final class AggregationParts
{
    public const PAGES = 'pages';
    public const ELEMENTS = 'elements';
    public const VISITS = 'visits';
    public const ALL = [self::PAGES, self::ELEMENTS, self::VISITS];

    /**
     * @param string|null $only Comma list from --only; null or '' selects every part
     * @param bool $pagesOnly The --pagesOnly flag from 0.24.2, an alias for --only=pages
     * @return string[] Selected parts in the order of ALL
     * @throws \InvalidArgumentException For an unknown part, or --pagesOnly next to a different --only
     */
    public static function resolve(?string $only, bool $pagesOnly = false): array
    {
        $only = strtolower(trim((string)$only));

        if ($pagesOnly) {
            if ($only !== '' && $only !== self::PAGES) {
                throw new \InvalidArgumentException("--pagesOnly cannot be combined with --only={$only}");
            }

            return [self::PAGES];
        }

        if ($only === '') {
            return self::ALL;
        }

        $names = array_filter(array_map('trim', explode(',', $only)), static fn(string $name) => $name !== '');
        $unknown = array_diff($names, self::ALL);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown part(s) for --only: ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', self::ALL)
            );
        }

        return array_values(array_intersect(self::ALL, $names));
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php tests/AnalyticsAggregationPartsTest.php`
Expected: `9 passed, 0 failed`

- [ ] **Step 7: Check the MySQL harness boots**

Run (local MariaDB; take the password from the local forum-holzkarriere `.env`, `DB_PASS`):
`CRELISH_TEST_MYSQL_DSN='mysql:host=127.0.0.1' CRELISH_TEST_MYSQL_USER=root CRELISH_TEST_MYSQL_PASSWORD='…' php -r 'require "tests/analytics/mysql.php"; analyticsMysqlApp(); echo implode(",", Yii::$app->db->schema->getTableNames()), "\n";'`
Expected: `analytics_element_daily,analytics_element_views,analytics_page_daily,analytics_page_views,analytics_sessions` (any order). Without the variables: `SKIPPED: …`.

- [ ] **Step 8: Commit**

```bash
git add tests/analytics/bootstrap.php tests/analytics/mysql.php components/Analytics/AggregationParts.php tests/AnalyticsAggregationPartsTest.php
git commit -m "test(analytics): shared harness and --only part selection"
```

---

### Task 2: `aggregateDate()` with independent parts, `--only` and repair mode

**Files:**
- Modify: `commands/AnalyticsAggregationController.php` (options, `actionDaily`, `actionMonthly`, `actionBackfill`; new `aggregateDate`, `aggregatePages`, `aggregateElements`, `runPart`, `mergeCounts`, `resolveParts`)
- Test: `tests/AnalyticsDailyMysqlTest.php`

**Interfaces:**
- Consumes: `AggregationParts::resolve()`, `AggregationParts::*` (Task 1); harness functions (Task 1).
- Produces: `public function aggregateDate(string $date, array $parts, bool $repair = false): bool` on `AnalyticsAggregationController` (true when every requested part succeeded); public property `$only` (`--only`); `AnalyticsRetention` is NOT used yet (the day range is computed inline here and moved in Task 4).

- [ ] **Step 1: Write the failing test**

`tests/AnalyticsDailyMysqlTest.php`:

```php
<?php

/**
 * Daily aggregation against MySQL: correct totals, independent parts,
 * --only / --pagesOnly, repair mode that never lowers, failing exit codes.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/AnalyticsDailyMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\commands\AnalyticsAggregationController;
use giantbits\crelish\components\Analytics\AggregationParts;

const P1 = 'a1000000-0000-4000-8000-000000000001';
const J1 = 'b1000000-0000-4000-8000-000000000001';
const C1 = 'c1000000-0000-4000-8000-000000000001';

function controller(): AnalyticsAggregationController
{
    $controller = new AnalyticsAggregationController('analytics-aggregation', Yii::$app);
    $controller->interactive = false;
    $controller->color = false;

    return $controller;
}

function fixtures(string $day): void
{
    session('s1');
    session('s2');
    session('bot', 1);
    pageView($day, '09:00:00', P1, '/jobs?x=1', 's1');
    pageView($day, '09:05:00', P1, '/jobs?x=2', 's2');
    pageView($day, '09:10:00', P1, '/jobs?x=3', 'bot', 1);
    elementView($day, '09:00:00', J1, 'list', C1, 's1');
    elementView($day, '09:01:00', J1, 'detail', C1, 's1');
    elementView($day, '09:02:00', J1, 'detail', C1, 's2');
    elementView($day, '09:03:00', J1, 'detail', C1, 'bot');
    elementView($day, '09:04:00', J1, null, C1, 's2');
}

function pageTotal(string $day): int
{
    return (int)scalar('SELECT COALESCE(SUM(total_views), 0) FROM analytics_page_daily WHERE date = :d', [':d' => $day]);
}

function elementTotal(string $day, ?string $event = null): int
{
    $sql = 'SELECT COALESCE(SUM(total_views), 0) FROM analytics_element_daily WHERE date = :d';
    $params = [':d' => $day];
    if ($event !== null) {
        $sql .= ' AND event_type = :e';
        $params[':e'] = $event;
    }

    return (int)scalar($sql, $params);
}

$day = daysAgo(3);

echo "Normal run\n";
analyticsMysqlApp(false);
fixtures($day);
check('both parts succeed', true, controller()->aggregateDate($day, [AggregationParts::PAGES, AggregationParts::ELEMENTS]));
check('one page row however many URL variants', 1, (int)scalar('SELECT COUNT(*) FROM analytics_page_daily WHERE date = :d', [':d' => $day]));
check('page views exclude bots', 2, pageTotal($day));
check('element detail views exclude the bot session', 2, elementTotal($day, 'detail'));
check('an element view without type is kept under an empty event type', 1, elementTotal($day, ''));
check('element views in total', 4, elementTotal($day));

echo "\nRerun overwrites\n";
Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 100')->execute();
controller()->aggregateDate($day, [AggregationParts::PAGES]);
check('a normal rerun overwrites a higher stored count', 2, pageTotal($day));

echo "\nRepair never lowers\n";
Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 100')->execute();
controller()->aggregateDate($day, [AggregationParts::PAGES], true);
check('repair keeps a higher stored count', 100, pageTotal($day));
Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 1')->execute();
controller()->aggregateDate($day, [AggregationParts::PAGES], true);
check('repair raises a lower stored count', 2, pageTotal($day));

echo "\nParts are independent\n";
Yii::$app->db->createCommand('DELETE FROM analytics_page_daily')->execute();
Yii::$app->db->createCommand('RENAME TABLE analytics_element_daily TO analytics_element_daily_gone')->execute();
check('a failing element part reports failure', false, controller()->aggregateDate($day, [AggregationParts::PAGES, AggregationParts::ELEMENTS]));
check('the page part still ran', 2, pageTotal($day));
check('backfill exits non-zero when a day fails', 1, controller()->runAction('backfill', ['3']));
Yii::$app->db->createCommand('RENAME TABLE analytics_element_daily_gone TO analytics_element_daily')->execute();

echo "\n--only and --pagesOnly\n";
Yii::$app->db->createCommand('DELETE FROM analytics_page_daily')->execute();
Yii::$app->db->createCommand('DELETE FROM analytics_element_daily')->execute();
check('--only=pages exits 0', 0, controller()->runAction('daily', [$day, 'only' => 'pages']));
check('--only=pages wrote pages', 2, pageTotal($day));
check('--only=pages left elements alone', 0, elementTotal($day));
Yii::$app->db->createCommand('DELETE FROM analytics_page_daily')->execute();
check('--pagesOnly=1 exits 0', 0, controller()->runAction('daily', [$day, 'pagesOnly' => '1']));
check('--pagesOnly=1 wrote pages', 2, pageTotal($day));
check('--pagesOnly=1 left elements alone', 0, elementTotal($day));
check('an unknown --only part is a usage error', 64, controller()->runAction('daily', [$day, 'only' => 'sessions']));

analyticsDone();
```

- [ ] **Step 2: Run it to verify it fails**

Run: `CRELISH_TEST_MYSQL_DSN='mysql:host=127.0.0.1' CRELISH_TEST_MYSQL_USER=root CRELISH_TEST_MYSQL_PASSWORD='…' php tests/AnalyticsDailyMysqlTest.php`
Expected: `Call to undefined method giantbits\crelish\commands\AnalyticsAggregationController::aggregateDate()`.

- [ ] **Step 3: Add the option and imports**

In `commands/AnalyticsAggregationController.php`, add to the `use` block:

```php
use giantbits\crelish\components\Analytics\AggregationParts;
use yii\db\Connection;
```

Below the `$pagesOnly` property, add:

```php
    /**
     * @var string|null daily/monthly/backfill: comma list of the parts to aggregate
     * (pages, elements, visits). Empty: all parts.
     */
    public $only;
```

Change the `$pagesOnly` docblock's first line to `@var bool Alias for --only=pages (0.24.2).` and add `'only',` after `'pagesOnly',` in `options()`.

- [ ] **Step 4: Replace `actionDaily` with a thin wrapper around `aggregateDate`**

Replace the whole of `actionDaily()` with:

```php
    /**
     * Aggregate yesterday's data (run daily via cron)
     *
     * @param string|null $date Optional date in Y-m-d format (default: yesterday)
     * @return int
     */
    public function actionDaily($date = null)
    {
        $targetDate = $date ?: date('Y-m-d', strtotime('-1 day'));
        $parts = $this->resolveParts();
        if ($parts === null) {
            return ExitCode::USAGE;
        }

        $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Daily Analytics Aggregation\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Target date: {$targetDate}\n");
        $this->stdout("Parts: " . implode(', ', $parts) . "\n\n");

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if (!$this->aggregateDate($targetDate, $parts)) {
            $this->stderr("\nDaily aggregation finished with errors\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nDaily aggregation completed successfully\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Aggregate one day's raw data.
     *
     * Each part runs on its own: a failing part is reported and the others still
     * run, so one broken table cannot silently leave the rest of the day empty.
     *
     * @param string $date Y-m-d
     * @param string[] $parts AggregationParts values
     * @param bool $repair Merge with GREATEST(stored, recomputed) instead of
     *                     overwriting, and never delete rows: used by the cleanup
     *                     to fill an undercount without lowering anything
     * @return bool true when every requested part succeeded
     */
    public function aggregateDate(string $date, array $parts, bool $repair = false): bool
    {
        $db = Yii::$app->db;
        $start = $date . ' 00:00:00';
        $end = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';
        $ok = true;

        if (in_array(AggregationParts::ELEMENTS, $parts, true)) {
            $ok = $this->runPart('element', fn() => $this->aggregateElements($db, $start, $end, $repair)) && $ok;
        }

        if (in_array(AggregationParts::PAGES, $parts, true)) {
            $ok = $this->runPart('page', fn() => $this->aggregatePages($db, $start, $end, $repair)) && $ok;
        }

        return $ok;
    }

    /**
     * @return string[]|null The parts from --only/--pagesOnly, or null after reporting a usage error
     */
    protected function resolveParts(): ?array
    {
        try {
            return AggregationParts::resolve($this->only, (bool)$this->pagesOnly);
        } catch (\InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return null;
        }
    }

    private function runPart(string $label, callable $aggregate): bool
    {
        try {
            $rows = $aggregate();
            $this->stdout("✓ Aggregated {$rows} {$label} records\n", Console::FG_GREEN);
            return true;
        } catch (\Throwable $e) {
            $this->stderr("✗ Error aggregating {$label} data: " . $e->getMessage() . "\n", Console::FG_RED);
            return false;
        }
    }

    /**
     * ON DUPLICATE KEY UPDATE clause for the count columns.
     */
    private static function mergeCounts(bool $repair): string
    {
        $set = [];
        foreach (['total_views', 'unique_sessions', 'unique_users'] as $column) {
            $set[] = $repair ? "{$column} = GREATEST({$column}, VALUES({$column}))" : "{$column} = VALUES({$column})";
        }
        $set[] = 'updated_at = NOW()';

        return implode(', ', $set);
    }

    /**
     * Element views by date, element, page and event type.
     * INNER JOIN excludes orphaned views and bot sessions. A missing type is
     * stored as '' (what non-strict MySQL did implicitly; strict mode would fail).
     */
    private function aggregateElements(Connection $db, string $start, string $end, bool $repair): int
    {
        return $db->createCommand("
            INSERT INTO {{%analytics_element_daily}}
            (date, element_uuid, element_type, page_uuid, event_type, total_views, unique_sessions, unique_users)
            SELECT
                DATE(ev.created_at) as date,
                ev.element_uuid,
                ev.element_type,
                ev.page_uuid,
                COALESCE(ev.type, '') as event_type,
                COUNT(*) as total_views,
                COUNT(DISTINCT ev.session_id) as unique_sessions,
                COUNT(DISTINCT CASE WHEN ev.user_id IS NOT NULL AND ev.user_id > 0 THEN ev.user_id END) as unique_users
            FROM {{%analytics_element_views}} ev
            INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
            WHERE ev.created_at >= :start AND ev.created_at < :end
                AND s.is_bot = 0
            GROUP BY DATE(ev.created_at), ev.element_uuid, ev.element_type, ev.page_uuid, COALESCE(ev.type, '')
            ON DUPLICATE KEY UPDATE " . self::mergeCounts($repair) . "
        ", [':start' => $start, ':end' => $end])->execute();
    }

    /**
     * Page views by date and page.
     *
     * One row per page, as the unique key (date, page_uuid) has it, with one of its
     * URLs as page_url. Grouping by url as well made one row per URL variant, and
     * ON DUPLICATE KEY UPDATE let each overwrite the last: every job detail URL is a
     * variant of the same page, so 2,511 views of a day were stored as 49.
     */
    private function aggregatePages(Connection $db, string $start, string $end, bool $repair): int
    {
        return $db->createCommand("
            INSERT INTO {{%analytics_page_daily}}
            (date, page_uuid, page_url, total_views, unique_sessions, unique_users)
            SELECT
                DATE(created_at) as date,
                page_uuid,
                MIN(url),
                COUNT(*) as total_views,
                COUNT(DISTINCT session_id) as unique_sessions,
                COUNT(DISTINCT CASE WHEN user_id IS NOT NULL AND user_id > 0 THEN user_id END) as unique_users
            FROM {{%analytics_page_views}}
            WHERE created_at >= :start AND created_at < :end AND is_bot = 0
            GROUP BY DATE(created_at), page_uuid
            ON DUPLICATE KEY UPDATE " . self::mergeCounts($repair) . "
        ", [':start' => $start, ':end' => $end])->execute();
    }
```

- [ ] **Step 5: Switch `monthly` to the resolved parts**

In `actionMonthly()`:
1. At the start of the method body, add:

```php
        $parts = $this->resolveParts();
        if ($parts === null) {
            return ExitCode::USAGE;
        }
```

2. Replace `if ($this->pagesOnly) {` (the element monthly branch) with `if (!in_array(AggregationParts::ELEMENTS, $parts, true)) {` and its message with `"Element monthly aggregates left as they are (--only)\n"`.
3. Replace `if ($pageDailyCount > 0) {` (the page monthly branch) with `if (!in_array(AggregationParts::PAGES, $parts, true)) {` + a new `$this->stdout("Page monthly aggregates left as they are (--only)\n");` followed by `} elseif ($pageDailyCount > 0) {`.

- [ ] **Step 6: Let `backfill` use `aggregateDate` and fail when a day fails**

Replace the loop body and the final return in `actionBackfill()`:

```php
        $parts = $this->resolveParts();
        if ($parts === null) {
            return ExitCode::USAGE;
        }

        $successCount = 0;
        $errorCount = 0;

        for ($i = $days; $i >= 1; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $this->stdout("[{$date}]\n", Console::FG_CYAN);

            if ($this->aggregateDate($date, $parts)) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_GREEN);
        $this->stdout("Backfill completed\n", Console::FG_GREEN);
        $this->stdout("  Success: {$successCount} days\n");
        if ($errorCount > 0) {
            $this->stderr("  Errors: {$errorCount} days\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
```

(The `$parts` block goes right after the header output, before the loop.)

- [ ] **Step 7: Run the tests**

Run: `php tests/AnalyticsAggregationPartsTest.php && CRELISH_TEST_MYSQL_DSN=… php tests/AnalyticsDailyMysqlTest.php`
Expected: both end with `0 failed`.

- [ ] **Step 8: Commit**

```bash
git add commands/AnalyticsAggregationController.php tests/AnalyticsDailyMysqlTest.php
git commit -m "feat(analytics): --only parts, repair mode and independent parts in daily aggregation"
```

---

### Task 3: `analytics_visits_daily` and its aggregation

**Files:**
- Create: `migrations/m261008_120000_create_analytics_visits_daily.php`
- Create: `components/Analytics/VisitsAggregator.php`
- Modify: `commands/AnalyticsAggregationController.php` (`aggregateDate`)
- Test: `tests/AnalyticsVisitsMysqlTest.php`

**Interfaces:**
- Consumes: `aggregateDate()` (Task 2), `AggregationParts::VISITS` (Task 1), harness (Task 1).
- Produces: `VisitsAggregator::TABLE = '{{%analytics_visits_daily}}'`, `VisitsAggregator::SOURCE_PAGES = 'pages'`, `VisitsAggregator::SOURCE_ELEMENTS = 'elements'`, `VisitsAggregator::tableExists(Connection $db): bool`, `new VisitsAggregator(Connection $db)`, `->aggregate(string $date, bool $repair = false): int`.

- [ ] **Step 1: Write the failing test**

`tests/AnalyticsVisitsMysqlTest.php`:

```php
<?php

/**
 * Daily visit rows: site and owner rows per event type, no collisions,
 * idempotent normal runs, repair never lowers, missing table tolerated.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/AnalyticsVisitsMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\commands\AnalyticsAggregationController;
use giantbits\crelish\components\Analytics\AggregationParts;
use giantbits\crelish\components\Analytics\VisitsAggregator;
use giantbits\crelish\migrations\m261008_120000_create_analytics_visits_daily;

const C1 = 'c1000000-0000-4000-8000-000000000001';
const C1_UPPER = 'C1000000-0000-4000-8000-000000000001';
const C2 = 'c2000000-0000-4000-8000-000000000002';
const J1 = 'b1000000-0000-4000-8000-000000000001';
const J2 = 'b2000000-0000-4000-8000-000000000002';
const J3 = 'b3000000-0000-4000-8000-000000000003';
const P1 = 'a1000000-0000-4000-8000-000000000001';
const P2 = 'a2000000-0000-4000-8000-000000000002';

function visit(string $day, string $source, string $owner, string $event): ?int
{
    $value = scalar(
        'SELECT unique_sessions FROM analytics_visits_daily WHERE date = :d AND source = :s AND owner_uuid = :o AND event_type = :e',
        [':d' => $day, ':s' => $source, ':o' => $owner, ':e' => $event]
    );

    return $value === false ? null : (int)$value;
}

function rowCount(string $day): int
{
    return (int)scalar('SELECT COUNT(*) FROM analytics_visits_daily WHERE date = :d', [':d' => $day]);
}

function fixtures(string $day): void
{
    foreach (['s1', 's2', 's3', 's5', 's6', 's7'] as $id) {
        session($id, 0, $id === 's1' ? 7 : null);
    }
    session('bot', 1);
    elementView($day, '08:00:00', J1, 'list', C1, 's1', 7);
    elementView($day, '08:01:00', J2, 'list', C1, 's1', 7);
    elementView($day, '08:02:00', J1, 'detail', C1, 's1', 7);
    elementView($day, '08:03:00', J2, 'detail', C1, 's2');
    elementView($day, '08:04:00', J3, 'list', C2, 's3');
    elementView($day, '08:05:00', J1, 'list', C1, 'bot');
    elementView($day, '08:06:00', J1, null, C1, 's5');
    elementView($day, '08:07:00', J1, 'list', '', 's6');
    elementView($day, '08:08:00', J1, 'detail', C1_UPPER, 's7');
    pageView($day, '08:00:00', P1, '/a', 's2');
    pageView($day, '08:01:00', P1, '/a?x', 's3');
    pageView($day, '08:02:00', P2, '/b', 's3');
    pageView($day, '08:03:00', P1, '/a', 'bot', 1);
}

$day = daysAgo(2);

echo "Rows for one day\n";
analyticsMysqlApp();
fixtures($day);
(new VisitsAggregator(Yii::$app->db))->aggregate($day);
check('site page visits exclude bots', 2, visit($day, 'pages', '', ''));
check('site element visits, any event', 6, visit($day, 'elements', '', ''));
check('site element visits, list', 3, visit($day, 'elements', '', 'list'));
check('site element visits, detail', 3, visit($day, 'elements', '', 'detail'));
check('company visits, any event, one visitor seeing several jobs counts once', 4, visit($day, 'elements', C1, ''));
check('company visits, list', 1, visit($day, 'elements', C1, 'list'));
check('company visits, detail, both UUID spellings in one row', 3, visit($day, 'elements', C1, 'detail'));
check('other company', 1, visit($day, 'elements', C2, ''));
check('logged-in users are counted', 1, (int)scalar("SELECT unique_users FROM analytics_visits_daily WHERE date = :d AND source = 'elements' AND owner_uuid = '' AND event_type = ''", [':d' => $day]));
check('a missing type creates no per-type row', 0, (int)scalar("SELECT COUNT(*) FROM analytics_visits_daily WHERE date = :d AND event_type NOT IN ('', 'list', 'detail')", [':d' => $day]));
check('one row per owner and event however the UUID is spelled', 1, (int)scalar("SELECT COUNT(*) FROM analytics_visits_daily WHERE date = :d AND owner_uuid = :o AND event_type = 'detail'", [':d' => $day, ':o' => C1]));
check('rows: site pages 1, site elements 3, C1 3, C2 2', 9, rowCount($day));

echo "\nNormal runs are idempotent and drop stale rows\n";
Yii::$app->db->createCommand()->insert('analytics_visits_daily', ['date' => $day, 'source' => 'elements', 'owner_uuid' => 'dead', 'event_type' => '', 'unique_sessions' => 5])->execute();
(new VisitsAggregator(Yii::$app->db))->aggregate($day);
check('a rerun writes the same rows', 9, rowCount($day));
check('a stale row is gone', null, visit($day, 'elements', 'dead', ''));

echo "\nRepair never lowers\n";
Yii::$app->db->createCommand()->insert('analytics_visits_daily', ['date' => $day, 'source' => 'elements', 'owner_uuid' => 'dead', 'event_type' => '', 'unique_sessions' => 5])->execute();
Yii::$app->db->createCommand("UPDATE analytics_visits_daily SET unique_sessions = 10 WHERE owner_uuid = :o AND event_type = ''", [':o' => C1])->execute();
Yii::$app->db->createCommand("UPDATE analytics_visits_daily SET unique_sessions = 1 WHERE owner_uuid = '' AND source = 'pages'")->execute();
(new VisitsAggregator(Yii::$app->db))->aggregate($day, true);
check('repair keeps a higher stored value', 10, visit($day, 'elements', C1, ''));
check('repair raises a lower stored value', 2, visit($day, 'pages', '', ''));
check('repair deletes nothing', 5, visit($day, 'elements', 'dead', ''));

echo "\nEmpty day\n";
check('a day without traffic writes no rows', 0, (new VisitsAggregator(Yii::$app->db))->aggregate(daysAgo(20)));

echo "\nThrough aggregateDate\n";
$controller = new AnalyticsAggregationController('analytics-aggregation', Yii::$app);
Yii::$app->db->createCommand('DELETE FROM analytics_visits_daily')->execute();
check('visits part succeeds', true, $controller->aggregateDate($day, [AggregationParts::VISITS]));
check('visits part wrote the rows', 9, rowCount($day));

echo "\nMissing table\n";
analyticsMysqlApp(false);
fixtures($day);
check('table reported missing', false, VisitsAggregator::tableExists(Yii::$app->db));
$controller = new AnalyticsAggregationController('analytics-aggregation', Yii::$app);
check('all parts still succeed', true, $controller->aggregateDate($day, AggregationParts::ALL));
check('pages were aggregated', 3, (int)scalar('SELECT SUM(total_views) FROM analytics_page_daily WHERE date = :d', [':d' => $day]));

echo "\nMigration\n";
$migration = new m261008_120000_create_analytics_visits_daily(['db' => Yii::$app->db, 'compact' => true]);
$migration->up();
check('migration creates the table', true, VisitsAggregator::tableExists(Yii::$app->db));
check('migration can run again', null, $migration->up() === false ? 'failed' : null);

analyticsDone();
```

- [ ] **Step 2: Run it to verify it fails**

Run: `CRELISH_TEST_MYSQL_DSN=… php tests/AnalyticsVisitsMysqlTest.php`
Expected: `Class "giantbits\crelish\migrations\m261008_120000_create_analytics_visits_daily" not found` (or `VisitsAggregator` not found).

- [ ] **Step 3: Write the migration**

`migrations/m261008_120000_create_analytics_visits_daily.php`:

```php
<?php
namespace giantbits\crelish\migrations;

use yii\db\Migration;

/**
 * Class m261008_120000_create_analytics_visits_daily
 *
 * Distinct visits (sessions) per day for the whole site and per owner (the
 * page_uuid element views carry; for jobs the owning company), so reports can
 * add up days without adding up the same visitor across pages, elements or
 * event types. Safe to run again: it does nothing when the table exists.
 */
class m261008_120000_create_analytics_visits_daily extends Migration
{
  public function safeUp()
  {
    if ($this->db->getTableSchema('{{%analytics_visits_daily}}', true) !== null) {
      return;
    }

    $tableOptions = $this->db->driverName === 'mysql'
      ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
      : null;

    $this->createTable('{{%analytics_visits_daily}}', [
      'id' => $this->primaryKey(),
      'date' => $this->date()->notNull(),
      'source' => $this->string(16)->notNull(),
      'owner_uuid' => $this->string(36)->notNull()->defaultValue(''),
      'event_type' => $this->string(50)->notNull()->defaultValue(''),
      'unique_sessions' => $this->integer()->notNull()->defaultValue(0),
      'unique_users' => $this->integer()->notNull()->defaultValue(0),
      'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
      'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
    ], $tableOptions);

    $this->createIndex('idx-visits_daily-unique', '{{%analytics_visits_daily}}', ['date', 'source', 'owner_uuid', 'event_type'], true);
    $this->createIndex('idx-visits_daily-owner', '{{%analytics_visits_daily}}', ['owner_uuid', 'date']);
  }

  public function safeDown()
  {
    $this->dropTable('{{%analytics_visits_daily}}');
  }
}
```

- [ ] **Step 4: Write `VisitsAggregator`**

`components/Analytics/VisitsAggregator.php`:

```php
<?php

namespace giantbits\crelish\components\Analytics;

use yii\db\Connection;

/**
 * Writes analytics_visits_daily: distinct sessions per day for the whole site
 * (owner '') and per owner (the element views' page_uuid), each for any event
 * ('') and per event type.
 *
 * Bot filtering matches the daily aggregates: page views by is_bot = 0, element
 * views through a session with is_bot = 0. Element views without a type only
 * count for the any-event rows; views without an owner only for the site rows,
 * so neither collides with the '' rows.
 */
final class VisitsAggregator
{
    public const TABLE = '{{%analytics_visits_daily}}';
    public const SOURCE_PAGES = 'pages';
    public const SOURCE_ELEMENTS = 'elements';

    public function __construct(private Connection $db)
    {
    }

    public static function tableExists(Connection $db): bool
    {
        return $db->getTableSchema(self::TABLE, true) !== null;
    }

    /**
     * Compute one day's visit rows.
     *
     * Normal mode replaces the day's rows (DELETE + INSERT in one transaction).
     * Repair mode keeps every existing row and merges with GREATEST().
     *
     * @return int Rows written
     */
    public function aggregate(string $date, bool $repair = false): int
    {
        $params = [
            ':date' => $date,
            ':start' => $date . ' 00:00:00',
            ':end' => date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00',
        ];
        $merge = $repair
            ? ' ON DUPLICATE KEY UPDATE unique_sessions = GREATEST(unique_sessions, VALUES(unique_sessions)),'
                . ' unique_users = GREATEST(unique_users, VALUES(unique_users)), updated_at = NOW()'
            : '';
        $insert = 'INSERT INTO ' . self::TABLE . ' (date, source, owner_uuid, event_type, unique_sessions, unique_users) ';
        $users = 'COUNT(DISTINCT CASE WHEN %1$s IS NOT NULL AND %1$s > 0 THEN %1$s END)';
        $elementUsers = sprintf($users, 'ev.user_id');
        $elements = "FROM {{%analytics_element_views}} ev
            INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
            WHERE ev.created_at >= :start AND ev.created_at < :end AND s.is_bot = 0";
        $typed = "AND ev.type IS NOT NULL AND ev.type <> ''";
        $owned = "AND ev.page_uuid <> ''";

        $statements = [
            // Site: page visits
            "SELECT :date, 'pages', '', '', COUNT(DISTINCT session_id), " . sprintf($users, 'user_id') . "
             FROM {{%analytics_page_views}}
             WHERE created_at >= :start AND created_at < :end AND is_bot = 0
             HAVING COUNT(*) > 0",
            // Site: element visits, any event
            "SELECT :date, 'elements', '', '', COUNT(DISTINCT ev.session_id), {$elementUsers}
             {$elements} HAVING COUNT(*) > 0",
            // Site: element visits per event type
            "SELECT :date, 'elements', '', ev.type, COUNT(DISTINCT ev.session_id), {$elementUsers}
             {$elements} {$typed} GROUP BY ev.type",
            // Owner: any event
            "SELECT :date, 'elements', ev.page_uuid, '', COUNT(DISTINCT ev.session_id), {$elementUsers}
             {$elements} {$owned} GROUP BY ev.page_uuid",
            // Owner: per event type
            "SELECT :date, 'elements', ev.page_uuid, ev.type, COUNT(DISTINCT ev.session_id), {$elementUsers}
             {$elements} {$owned} {$typed} GROUP BY ev.page_uuid, ev.type",
        ];

        $transaction = $this->db->beginTransaction();
        try {
            if (!$repair) {
                $this->db->createCommand()->delete(self::TABLE, ['date' => $date])->execute();
            }

            $written = 0;
            foreach ($statements as $select) {
                $written += $this->db->createCommand($insert . $select . $merge, $params)->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $written;
    }
}
```

(In repair mode MySQL counts an updated row as 2 affected rows; the return value is informational only.)

- [ ] **Step 5: Wire visits into `aggregateDate`**

In `commands/AnalyticsAggregationController.php` add `use giantbits\crelish\components\Analytics\VisitsAggregator;` and, in `aggregateDate()` before `return $ok;`:

```php
        if (in_array(AggregationParts::VISITS, $parts, true)) {
            if (VisitsAggregator::tableExists($db)) {
                $ok = $this->runPart('visit', fn() => (new VisitsAggregator($db))->aggregate($date, $repair)) && $ok;
            } else {
                $this->stderr("! Visits skipped: table analytics_visits_daily missing (run yii crelish-migrate/up)\n", Console::FG_YELLOW);
            }
        }
```

- [ ] **Step 6: Run the tests**

Run: `CRELISH_TEST_MYSQL_DSN=… php tests/AnalyticsVisitsMysqlTest.php && CRELISH_TEST_MYSQL_DSN=… php tests/AnalyticsDailyMysqlTest.php`
Expected: both `0 failed`. (The daily test builds its database with `analyticsMysqlApp(false)`, i.e. without the visits table, so the new visits step only prints its "skipped" warning there.)

- [ ] **Step 7: Commit**

```bash
git add migrations/m261008_120000_create_analytics_visits_daily.php components/Analytics/VisitsAggregator.php commands/AnalyticsAggregationController.php tests/AnalyticsVisitsMysqlTest.php
git commit -m "feat(analytics): daily visit counts per site and owner in analytics_visits_daily"
```

---

### Task 3b: Company visits follow element ownership

(Added during execution by controller ruling: at forum-holzbranche `page_uuid` is never the owning company, so owner rows keyed by `page_uuid` alone would show 0 visits per company. Spec §2 "Owners" was updated accordingly in commit cb865a4.)

**Files:**
- Create: `components/Analytics/ElementOwnership.php`
- Modify: `components/Analytics/VisitsAggregator.php`
- Test: `tests/AnalyticsVisitsOwnershipMysqlTest.php`

**Interfaces:**
- Consumes: `VisitsAggregator` as committed in Task 3 (2851d55); harness `analyticsMysqlApp`, `session`, `elementView`, `daysAgo`, `scalar`, `check`, `analyticsDone`.
- Produces: `ElementOwnership::companyOwnedTables(Connection $db, ?array $config = null): array` (element type => table name, only tables that exist and have a `company` column; `$config === null` reads `@app/config/analytics-element-types.php`, missing file = `[]`); `VisitsAggregator::__construct(Connection $db, ?array $ownedTables = null)` (null = `ElementOwnership::companyOwnedTables($db)`). `aggregate()`'s signature and the `$params` array (`:date`, `:start`, `:end`) stay as they are — Task 4 edits that array.

**Rule:** a session counts for an owner when it saw an element whose `page_uuid` is the owner, OR an element of a type in `$ownedTables` whose table row (`uuid` = element_uuid) has that owner in `company`. A session matching an owner both ways counts once. Bot filtering as for every element visit row (join sessions, `is_bot = 0`). Ownership joins convert both sides with `CONVERT(col USING utf8mb4) COLLATE utf8mb4_unicode_ci` (project tables mix utf8mb3/utf8mb4 and general/unicode collations). Each statement binds only the placeholders it uses.

- [ ] **Step 1: Write the failing test**

`tests/AnalyticsVisitsOwnershipMysqlTest.php`:

```php
<?php

/**
 * Owner rows by page_uuid OR by element ownership (tables with a company
 * column), across utf8mb3/utf8mb4 tables, each session once per owner.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/AnalyticsVisitsOwnershipMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\components\Analytics\ElementOwnership;
use giantbits\crelish\components\Analytics\VisitsAggregator;

const PAGE_A = 'a1000000-0000-4000-8000-00000000000a';
const X = 'c9000000-0000-4000-8000-000000000009';   // owns product P1 (forum-holzbranche style)
const C1 = 'c1000000-0000-4000-8000-000000000001';  // owns job J1 and is its page_uuid (forum-holzkarriere style)
const P1 = 'd1000000-0000-4000-8000-000000000001';
const P2 = 'd2000000-0000-4000-8000-000000000002';  // product without owner
const J1 = 'b1000000-0000-4000-8000-000000000001';
const OWNED = ['product' => 'product', 'job' => 'job'];

function visit(string $day, string $owner, string $event): ?int
{
    $value = scalar(
        "SELECT unique_sessions FROM analytics_visits_daily WHERE date = :d AND source = 'elements' AND owner_uuid = :o AND event_type = :e",
        [':d' => $day, ':o' => $owner, ':e' => $event]
    );

    return $value === false ? null : (int)$value;
}

$day = daysAgo(2);
analyticsMysqlApp();
$db = Yii::$app->db;

// Project tables as found in production: utf8mb3 general_ci next to utf8mb4 unicode_ci
$db->createCommand("CREATE TABLE product (uuid varchar(36) NOT NULL PRIMARY KEY, company varchar(36) NULL) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci")->execute();
$db->createCommand("CREATE TABLE job (uuid varchar(36) NOT NULL PRIMARY KEY, company varchar(36) NULL) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")->execute();
$db->createCommand("CREATE TABLE news (uuid varchar(36) NOT NULL PRIMARY KEY, title varchar(100) NULL)")->execute();
$db->createCommand()->insert('product', ['uuid' => P1, 'company' => X])->execute();
$db->createCommand()->insert('product', ['uuid' => P2, 'company' => ''])->execute();
$db->createCommand()->insert('job', ['uuid' => J1, 'company' => C1])->execute();

foreach (['s1', 's2', 's3', 's4'] as $id) {
    session($id);
}
session('bot', 1);
elementView($day, '09:00:00', P1, 'list', PAGE_A, 's1', null, 'product');
elementView($day, '09:01:00', P1, 'detail', PAGE_A, 's1', null, 'product');
elementView($day, '09:02:00', P1, 'detail', PAGE_A, 's2', null, 'product');
elementView($day, '09:03:00', J1, 'detail', C1, 's3', null, 'job');
elementView($day, '09:04:00', P2, 'list', PAGE_A, 's4', null, 'product');
elementView($day, '09:05:00', P1, 'detail', PAGE_A, 'bot', null, 'product');

echo "Ownership tables\n";
check('only listed, existing tables with a company column', OWNED, ElementOwnership::companyOwnedTables($db, [
    'product' => ['table' => 'product'],
    'job' => ['table' => 'job'],
    'news' => ['table' => 'news'],
    'gone' => ['table' => 'missing_table'],
    'broken' => 'not-an-array',
]));
check('no project config means no ownership', [], ElementOwnership::companyOwnedTables($db));

echo "\nOwner rows\n";
(new VisitsAggregator($db, OWNED))->aggregate($day);
check('the owning company counts the visitors of its product', 2, visit($day, X, ''));
check('per event type for the owner, bots excluded', [1, 2], [visit($day, X, 'list'), visit($day, X, 'detail')]);
check('the page an element was shown on is still an owner', 3, visit($day, PAGE_A, ''));
check('page_uuid and ownership pointing at the same company count once', 1, visit($day, C1, ''));
check('no other owners (an element without owner adds none)', 0, (int)scalar(
    "SELECT COUNT(*) FROM analytics_visits_daily WHERE date = :d AND owner_uuid NOT IN ('', :x, :p, :c)",
    [':d' => $day, ':x' => X, ':p' => PAGE_A, ':c' => C1]
));
check('site rows unchanged by ownership', 4, visit($day, '', ''));

echo "\nRepeatable\n";
(new VisitsAggregator($db, OWNED))->aggregate($day);
check('a rerun gives the same owner rows', [2, 3, 1], [visit($day, X, ''), visit($day, PAGE_A, ''), visit($day, C1, '')]);
(new VisitsAggregator($db, OWNED))->aggregate($day, true);
check('repair keeps them', 2, visit($day, X, ''));
$db->createCommand('CREATE TEMPORARY TABLE tmp_visit_owners (x int)')->execute();
$db->createCommand('DROP TEMPORARY TABLE tmp_visit_owners')->execute();
check('the temporary table is dropped after each run', true, true);

analyticsDone();
```

- [ ] **Step 2: Run it to verify it fails**

Run (with the `CRELISH_TEST_MYSQL_*` exports): `php tests/AnalyticsVisitsOwnershipMysqlTest.php`
Expected: `Class "giantbits\crelish\components\Analytics\ElementOwnership" not found`.

- [ ] **Step 3: Write `ElementOwnership`**

`components/Analytics/ElementOwnership.php`:

```php
<?php

namespace giantbits\crelish\components\Analytics;

use Yii;
use yii\db\Connection;

/**
 * Which element tables say who owns a row: the element types listed in the
 * project's config/analytics-element-types.php whose table has a `company`
 * column. The company statistics use the same tables to find a company's content.
 */
final class ElementOwnership
{
    /**
     * @param array|null $config The element types config; null reads @app/config/analytics-element-types.php
     * @return array<string, string> element type => table name
     */
    public static function companyOwnedTables(Connection $db, ?array $config = null): array
    {
        if ($config === null) {
            $file = Yii::getAlias('@app/config/analytics-element-types.php', false);
            $config = ($file && is_file($file)) ? (array)(include $file) : [];
        }

        $tables = [];
        foreach ($config as $type => $definition) {
            $table = is_array($definition) ? ($definition['table'] ?? null) : null;
            if (!is_string($table) || $table === '') {
                continue;
            }

            $schema = $db->getTableSchema($table, true);
            if ($schema !== null && isset($schema->columns['company'])) {
                $tables[(string)$type] = $table;
            }
        }

        return $tables;
    }
}
```

- [ ] **Step 4: Route owner rows through ownership in `VisitsAggregator`**

1. Replace the constructor with:

```php
    /** @var array<string, string> element type => table whose `company` column owns its rows */
    private array $ownedTables;

    /**
     * @param array<string, string>|null $ownedTables Element type => table with a `company` column;
     *        null reads them from the project config via ElementOwnership
     */
    public function __construct(private Connection $db, ?array $ownedTables = null)
    {
        $this->ownedTables = $ownedTables ?? ElementOwnership::companyOwnedTables($db);
    }
```

2. In `aggregate()`, delete the `$owned` variable and the two `// Owner: …` entries from `$statements` (the three site statements stay). Inside the `try`, directly after the `foreach ($statements …)` loop, add:

```php
            $written += $this->aggregateOwners($params, $insert, $merge);
```

3. Update the class docblock's second paragraph: owners are the element views' `page_uuid` or the company owning the element (via `ElementOwnership`), a session counting once per owner.

4. Add the method:

```php
    /**
     * Owner rows: a session counts for an owner when it saw an element whose
     * page_uuid is the owner, or an element the owner owns ($ownedTables). The
     * candidates are collected in a temporary table, so a session matching an
     * owner both ways counts once and each statement binds only its own
     * placeholders. Both sides of the ownership join are converted to
     * utf8mb4_unicode_ci: project tables mix utf8mb3/utf8mb4 and
     * general/unicode collations.
     */
    private function aggregateOwners(array $params, string $insert, string $merge): int
    {
        $tmp = 'tmp_visit_owners';
        $range = [':start' => $params[':start'], ':end' => $params[':end']];
        $views = "FROM {{%analytics_element_views}} ev
            INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id";
        $where = "WHERE ev.created_at >= :start AND ev.created_at < :end AND s.is_bot = 0";
        $utf8 = static fn(string $column): string => "CONVERT({$column} USING utf8mb4) COLLATE utf8mb4_unicode_ci";

        $this->db->createCommand("DROP TEMPORARY TABLE IF EXISTS {$tmp}")->execute();
        $this->db->createCommand("CREATE TEMPORARY TABLE {$tmp} (
                session_id varchar(100) NOT NULL,
                user_id int NULL,
                type varchar(255) NULL,
                owner varchar(36) NOT NULL
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")->execute();

        try {
            $this->db->createCommand("INSERT INTO {$tmp} (session_id, user_id, type, owner)
                SELECT ev.session_id, ev.user_id, ev.type, ev.page_uuid {$views}
                {$where} AND ev.page_uuid <> ''", $range)->execute();

            foreach ($this->ownedTables as $type => $table) {
                $this->db->createCommand("INSERT INTO {$tmp} (session_id, user_id, type, owner)
                    SELECT ev.session_id, ev.user_id, ev.type, x.company {$views}
                    INNER JOIN " . $this->db->quoteTableName($table) . " x
                        ON " . $utf8('x.uuid') . " = " . $utf8('ev.element_uuid') . "
                    {$where} AND ev.element_type = :type AND x.company IS NOT NULL AND x.company <> ''",
                    $range + [':type' => $type])->execute();
            }

            $users = 'COUNT(DISTINCT CASE WHEN user_id IS NOT NULL AND user_id > 0 THEN user_id END)';
            $date = [':date' => $params[':date']];
            $written = $this->db->createCommand($insert
                . "SELECT :date, 'elements', owner, '', COUNT(DISTINCT session_id), {$users}
                   FROM {$tmp} GROUP BY owner" . $merge, $date)->execute();
            $written += $this->db->createCommand($insert
                . "SELECT :date, 'elements', owner, type, COUNT(DISTINCT session_id), {$users}
                   FROM {$tmp} WHERE type IS NOT NULL AND type <> '' GROUP BY owner, type" . $merge, $date)->execute();

            return $written;
        } finally {
            $this->db->createCommand("DROP TEMPORARY TABLE IF EXISTS {$tmp}")->execute();
        }
    }
```

- [ ] **Step 5: Run the tests**

Run: `php tests/AnalyticsVisitsOwnershipMysqlTest.php && php tests/AnalyticsVisitsMysqlTest.php && php tests/AnalyticsDailyMysqlTest.php` (with the exports).
Expected: all `0 failed`. `AnalyticsVisitsMysqlTest` must pass unchanged: without a project config the owner rows come from `page_uuid` alone, and the temporary table groups upper/lower-case UUID spellings like the old `GROUP BY ev.page_uuid` did.

- [ ] **Step 6: Commit**

```bash
git add components/Analytics/ElementOwnership.php components/Analytics/VisitsAggregator.php tests/AnalyticsVisitsOwnershipMysqlTest.php
git commit -m "feat(analytics): company visits count the elements a company owns, not only its page_uuid"
```

---

### Task 4: Retention days and the per-day decision

**Files:**
- Create: `components/Analytics/AnalyticsRetention.php`
- Create: `components/Analytics/AnalyticsDayCheck.php`
- Modify: `commands/AnalyticsAggregationController.php` (use `AnalyticsRetention::dayRange` in `aggregateDate`)
- Modify: `components/Analytics/VisitsAggregator.php` (use `AnalyticsRetention::dayRange`)
- Test: `tests/AnalyticsRetentionTest.php`

**Interfaces:**
- Produces: `AnalyticsRetention::firstKeptDay(string $today, int $retentionDays): string`, `AnalyticsRetention::daysToDelete(?string $oldestRawDay, string $firstKeptDay): string[]`, `AnalyticsRetention::dayRange(string $date): array{0: string, 1: string}`, `AnalyticsRetention::ranges(array $days): array<int, array{0: string, 1: string}>`; `AnalyticsDayCheck::shortfalls(array $stored, array $raw): string[]`.

- [ ] **Step 1: Write the failing test**

`tests/AnalyticsRetentionTest.php`:

```php
<?php

/**
 * Which days the cleanup may delete, and when a day's aggregates are short.
 *
 * Run with:  php tests/AnalyticsRetentionTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/bootstrap.php';

use giantbits\crelish\components\Analytics\AnalyticsDayCheck;
use giantbits\crelish\components\Analytics\AnalyticsRetention;

echo "Retention boundary\n";
check('first kept day is today minus retention', '2026-09-08', AnalyticsRetention::firstKeptDay('2026-10-08', 30));
check('across a year boundary', '2025-12-02', AnalyticsRetention::firstKeptDay('2026-01-01', 30));
checkThrows('retention below one day is refused', fn() => AnalyticsRetention::firstKeptDay('2026-10-08', 0), \InvalidArgumentException::class);

echo "\nDays to delete\n";
check('no raw data', [], AnalyticsRetention::daysToDelete(null, '2026-09-08'));
check('whole days before the first kept day', ['2026-09-05', '2026-09-06', '2026-09-07'], AnalyticsRetention::daysToDelete('2026-09-05', '2026-09-08'));
check('a timestamp counts as its day', ['2026-09-07'], AnalyticsRetention::daysToDelete('2026-09-07 23:59:59', '2026-09-08'));
check('nothing when the oldest day is kept', [], AnalyticsRetention::daysToDelete('2026-09-08', '2026-09-08'));

echo "\nDay ranges\n";
check('a day is half-open midnight to midnight', ['2026-02-28 00:00:00', '2026-03-01 00:00:00'], AnalyticsRetention::dayRange('2026-02-28'));
check('the DST day still ends at the next midnight', ['2026-03-29 00:00:00', '2026-03-30 00:00:00'], AnalyticsRetention::dayRange('2026-03-29'));
check('consecutive days merge, gaps split', [
    ['2026-09-01 00:00:00', '2026-09-03 00:00:00'],
    ['2026-09-04 00:00:00', '2026-09-05 00:00:00'],
], AnalyticsRetention::ranges(['2026-09-04', '2026-09-01', '2026-09-02']));
check('no days, no ranges', [], AnalyticsRetention::ranges([]));

echo "\nShortfalls\n";
check('stored equal to raw passes', [], AnalyticsDayCheck::shortfalls(['page_views' => 5], ['page_views' => 5]));
check('stored above raw passes (bots removed later)', [], AnalyticsDayCheck::shortfalls(['page_views' => 9], ['page_views' => 5]));
check('stored below raw is short', ['page_views'], AnalyticsDayCheck::shortfalls(['page_views' => 4], ['page_views' => 5]));
check('no stored row while raw has traffic is short', ['element_views'], AnalyticsDayCheck::shortfalls([], ['element_views' => 3]));
check('nothing reportable passes', [], AnalyticsDayCheck::shortfalls([], ['page_views' => 0, 'element_views' => 0]));
check('stored metrics without raw counterpart are ignored', [], AnalyticsDayCheck::shortfalls(['page_visits' => 1], []));
check('several shortfalls in raw order', ['page_views', 'page_visits'], AnalyticsDayCheck::shortfalls(
    ['page_views' => 1, 'page_visits' => 0, 'element_views' => 9],
    ['page_views' => 2, 'page_visits' => 1, 'element_views' => 9]
));

analyticsDone();
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/AnalyticsRetentionTest.php`
Expected: `Class "giantbits\crelish\components\Analytics\AnalyticsRetention" not found`.

- [ ] **Step 3: Implement both classes**

`components/Analytics/AnalyticsRetention.php`:

```php
<?php

namespace giantbits\crelish\components\Analytics;

/**
 * Which whole days of raw analytics data are due for deletion.
 *
 * Deleting by a timestamp ("now minus 30 days") cut one day in two every night:
 * its early hours were gone while the rest stayed, a state that can neither be
 * verified nor repaired. Days are therefore always handled whole.
 */
final class AnalyticsRetention
{
    /**
     * The oldest day whose raw data is kept; every earlier day may be deleted.
     */
    public static function firstKeptDay(string $today, int $retentionDays): string
    {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('retentionDays must be at least 1');
        }

        return (new \DateTimeImmutable(substr($today, 0, 10)))->modify("-{$retentionDays} days")->format('Y-m-d');
    }

    /**
     * Days from the oldest raw day up to, not including, the first kept day.
     *
     * @param string|null $oldestRawDay Y-m-d or a timestamp; null when there is no raw data
     * @return string[] Y-m-d, ascending
     */
    public static function daysToDelete(?string $oldestRawDay, string $firstKeptDay): array
    {
        if ($oldestRawDay === null || $oldestRawDay === '') {
            return [];
        }

        $days = [];
        $day = new \DateTimeImmutable(substr($oldestRawDay, 0, 10));
        $end = new \DateTimeImmutable($firstKeptDay);

        for (; $day < $end; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /**
     * @return array{0: string, 1: string} Half-open [start, end) of a day
     */
    public static function dayRange(string $date): array
    {
        $day = new \DateTimeImmutable(substr($date, 0, 10));

        return [$day->format('Y-m-d') . ' 00:00:00', $day->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
    }

    /**
     * Merge days into half-open ranges of consecutive days, so deleting them
     * takes one statement per range instead of one per day.
     *
     * @param string[] $days Y-m-d in any order
     * @return array<int, array{0: string, 1: string}>
     */
    public static function ranges(array $days): array
    {
        $days = array_values(array_unique($days));
        sort($days);

        $ranges = [];
        foreach ($days as $day) {
            [$start, $end] = self::dayRange($day);
            $last = count($ranges) - 1;

            if ($last >= 0 && $ranges[$last][1] === $start) {
                $ranges[$last][1] = $end;
            } else {
                $ranges[] = [$start, $end];
            }
        }

        return $ranges;
    }
}
```

`components/Analytics/AnalyticsDayCheck.php`:

```php
<?php

namespace giantbits\crelish\components\Analytics;

/**
 * Whether a day's stored aggregates cover its raw data.
 *
 * Stored may exceed raw: bot detection re-scores the last 30 days and deletes
 * raw rows of sessions found to be bots after the day was aggregated. Stored
 * numbers are deliberately not lowered to follow; only an undercount (stored
 * below raw) means traffic would be lost by deleting the raw data.
 */
final class AnalyticsDayCheck
{
    /**
     * @param array<string, int> $stored Metric => stored count (missing = no aggregate row)
     * @param array<string, int> $raw Metric => count in the raw data
     * @return string[] Metrics whose stored count is below the raw count, in the order of $raw
     */
    public static function shortfalls(array $stored, array $raw): array
    {
        $short = [];
        foreach ($raw as $metric => $count) {
            if ((int)($stored[$metric] ?? 0) < (int)$count) {
                $short[] = $metric;
            }
        }

        return $short;
    }
}
```

- [ ] **Step 4: Use `dayRange` where days are computed today**

In `aggregateDate()` (controller) replace the two lines computing `$start` / `$end` with:

```php
        [$start, $end] = AnalyticsRetention::dayRange($date);
```

and add `use giantbits\crelish\components\Analytics\AnalyticsRetention;`. In `VisitsAggregator::aggregate()` replace the `:start` / `:end` entries with:

```php
        [$start, $end] = AnalyticsRetention::dayRange($date);
        $params = [':date' => $date, ':start' => $start, ':end' => $end];
```

- [ ] **Step 5: Run the tests**

Run: `php tests/AnalyticsRetentionTest.php && CRELISH_TEST_MYSQL_DSN=… php tests/AnalyticsDailyMysqlTest.php && CRELISH_TEST_MYSQL_DSN=… php tests/AnalyticsVisitsMysqlTest.php`
Expected: all `0 failed`.

- [ ] **Step 6: Commit**

```bash
git add components/Analytics/AnalyticsRetention.php components/Analytics/AnalyticsDayCheck.php components/Analytics/VisitsAggregator.php commands/AnalyticsAggregationController.php tests/AnalyticsRetentionTest.php
git commit -m "feat(analytics): whole-day retention ranges and the per-day aggregate check"
```

---

### Task 5: Cleanup verifies, repairs, then deletes

**Files:**
- Create: `components/Analytics/DayVerifier.php`
- Modify: `commands/AnalyticsAggregationController.php` (`actionCleanup` rewritten; `findUnaggregatedDays` and `findGapDays` removed; new `verifyDays`, `oldestRawDay`)
- Test: `tests/AnalyticsCleanupMysqlTest.php`

**Interfaces:**
- Consumes: `AnalyticsRetention::*`, `AnalyticsDayCheck::shortfalls` (Task 4), `aggregateDate()` (Task 2), `VisitsAggregator::tableExists/TABLE` (Task 3).
- Produces: `new DayVerifier(Connection $db, bool $withVisits)`, `->counts(string $date): array{raw: array<string,int>, stored: array<string,int>}` with metrics `page_views`, `element_views` and, with visits, `page_visits`, `element_visits`; controller `protected function verifyDays(array $days): array{0: string[], 1: string[]}`.

- [ ] **Step 1: Write the failing test**

`tests/AnalyticsCleanupMysqlTest.php`:

```php
<?php

/**
 * Cleanup: whole days, verification against raw data, repair that only raises,
 * keeping and reporting days that cannot be repaired.
 *
 * Run with:  CRELISH_TEST_MYSQL_DSN=... php tests/AnalyticsCleanupMysqlTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/mysql.php';

use giantbits\crelish\commands\AnalyticsAggregationController;
use giantbits\crelish\components\Analytics\AggregationParts;

const P1 = 'a1000000-0000-4000-8000-000000000001';
const J1 = 'b1000000-0000-4000-8000-000000000001';
const C1 = 'c1000000-0000-4000-8000-000000000001';

/** Refuses to repair one day, to exercise the "kept" path */
class NoRepairController extends AnalyticsAggregationController
{
    public string $broken = '';

    public function aggregateDate(string $date, array $parts, bool $repair = false): bool
    {
        return $repair && $date === $this->broken ? false : parent::aggregateDate($date, $parts, $repair);
    }
}

function cleanup(array $options = [], string $class = AnalyticsAggregationController::class, string $broken = ''): int
{
    $controller = new $class('analytics-aggregation', Yii::$app);
    $controller->interactive = false;
    $controller->color = false;
    if ($controller instanceof NoRepairController) {
        $controller->broken = $broken;
    }

    return $controller->runAction('cleanup', array_merge(['retentionDays' => '30', 'force' => '1'], $options));
}

function aggregate(string $day): void
{
    $controller = new AnalyticsAggregationController('analytics-aggregation', Yii::$app);
    $controller->aggregateDate($day, AggregationParts::ALL);
}

function rawPages(string $day): int
{
    return (int)scalar('SELECT COUNT(*) FROM analytics_page_views WHERE created_at >= :s AND created_at < :e', [':s' => "$day 00:00:00", ':e' => date('Y-m-d', strtotime("$day +1 day")) . ' 00:00:00']);
}

function storedPages(string $day): int
{
    return (int)scalar('SELECT COALESCE(SUM(total_views), 0) FROM analytics_page_daily WHERE date = :d', [':d' => $day]);
}

$correct = daysAgo(40);
$short = daysAgo(39);
$surplus = daysAgo(38);
$botsOnly = daysAgo(37);
$never = daysAgo(36);
$broken = daysAgo(35);
$lastDeleted = daysAgo(31);
$firstKept = daysAgo(30);

function fixtures(): void
{
    global $correct, $short, $surplus, $botsOnly, $never, $broken, $lastDeleted, $firstKept;

    session('s1');
    session('s2');
    session('s3');
    session('bot', 1);
    foreach ([$correct, $short, $surplus, $never, $broken, $lastDeleted] as $day) {
        pageView($day, '10:00:00', P1, '/a', 's1');
        pageView($day, '11:00:00', P1, '/a?x', 's2');
        elementView($day, '10:00:00', J1, 'detail', C1, 's1');
    }
    pageView($short, '12:00:00', P1, '/a?y', 's3');
    pageView($lastDeleted, '23:59:00', P1, '/a', 's3');
    pageView($botsOnly, '10:00:00', P1, '/a', 'bot', 1);
    pageView($firstKept, '00:30:00', P1, '/a', 's1');

    foreach ([$correct, $short, $surplus, $broken, $lastDeleted] as $day) {
        aggregate($day);
    }
    // $short: stored before its third view arrived
    Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 2 WHERE date = :d', [':d' => $short])->execute();
    // $surplus: stored before late bot removal deleted raw rows
    Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 5 WHERE date = :d', [':d' => $surplus])->execute();
    // $broken: undercounted, and its repair will fail
    Yii::$app->db->createCommand('UPDATE analytics_page_daily SET total_views = 1 WHERE date = :d', [':d' => $broken])->execute();
}

echo "Verify, repair, delete\n";
analyticsMysqlApp();
fixtures();
check('cleanup exits 0 when every day passes', 0, cleanup());
check('a correct day is deleted', 0, rawPages($correct));
check('a correct day keeps its aggregate', 2, storedPages($correct));
check('an undercounted day is repaired before deletion', 3, storedPages($short));
check('the repaired day is deleted', 0, rawPages($short));
check('stored above raw is left as it is', 5, storedPages($surplus));
check('a never aggregated day is aggregated, then deleted', [2, 0], [storedPages($never), rawPages($never)]);
check('a day with only bot traffic is not blocked', 1, rawPages($botsOnly));
check('the last day before the boundary is deleted whole', 0, rawPages($lastDeleted));
check('the first kept day is untouched, early hours included', 1, rawPages($firstKept));
check('visits were filled for the repaired never-aggregated day', 2, (int)scalar("SELECT unique_sessions FROM analytics_visits_daily WHERE date = :d AND source = 'pages' AND owner_uuid = ''", [':d' => $never]));
check('element views of deleted days are gone', 0, (int)scalar('SELECT COUNT(*) FROM analytics_element_views WHERE created_at < :c', [':c' => "$firstKept 00:00:00"]));

echo "\nA day that cannot be repaired is kept and reported\n";
analyticsMysqlApp();
fixtures();
check('cleanup exits non-zero', 1, cleanup([], NoRepairController::class, $broken));
check('the broken day keeps its raw data', 2, rawPages($broken));
check('other days are still deleted', 0, rawPages($correct));

echo "\nDry run\n";
analyticsMysqlApp();
fixtures();
check('dry run exits 0', 0, cleanup(['dryRun' => '1']));
check('dry run deletes nothing', 3, rawPages($short));
check('dry run repairs nothing', 2, storedPages($short));

echo "\n--skipAggregationCheck\n";
analyticsMysqlApp();
fixtures();
check('skip exits 0', 0, cleanup(['skipAggregationCheck' => '1']));
check('skip deletes without repairing', [2, 0], [storedPages($short), rawPages($short)]);

echo "\nWithout the visits table\n";
analyticsMysqlApp(false);
fixtures();
check('cleanup still exits 0', 0, cleanup());
check('pages are still verified and repaired', 3, storedPages($short));

analyticsDone();
```

- [ ] **Step 2: Run it to verify it fails**

Run: `CRELISH_TEST_MYSQL_DSN=… php tests/AnalyticsCleanupMysqlTest.php`
Expected: FAIL lines, e.g. `an undercounted day is repaired before deletion` expected 3, actual 2 (the old cleanup does not repair) and `the first kept day is untouched` failing.

- [ ] **Step 3: Write `DayVerifier`**

`components/Analytics/DayVerifier.php`:

```php
<?php

namespace giantbits\crelish\components\Analytics;

use yii\db\Connection;

/**
 * Raw and stored counts of one day, filtered exactly as the aggregation filters.
 */
final class DayVerifier
{
    public function __construct(private Connection $db, private bool $withVisits)
    {
    }

    /**
     * @return array{raw: array<string, int>, stored: array<string, int>}
     */
    public function counts(string $date): array
    {
        [$start, $end] = AnalyticsRetention::dayRange($date);
        $range = [':start' => $start, ':end' => $end];
        $day = [':date' => $date];
        $pages = "FROM {{%analytics_page_views}} WHERE created_at >= :start AND created_at < :end AND is_bot = 0";
        $elements = "FROM {{%analytics_element_views}} ev
            INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
            WHERE ev.created_at >= :start AND ev.created_at < :end AND s.is_bot = 0";

        $raw = [
            'page_views' => $this->int("SELECT COUNT(*) {$pages}", $range),
            'element_views' => $this->int("SELECT COUNT(*) {$elements}", $range),
        ];
        $stored = [
            'page_views' => $this->int('SELECT SUM(total_views) FROM {{%analytics_page_daily}} WHERE date = :date', $day),
            'element_views' => $this->int('SELECT SUM(total_views) FROM {{%analytics_element_daily}} WHERE date = :date', $day),
        ];

        if ($this->withVisits) {
            $site = 'SELECT unique_sessions FROM ' . VisitsAggregator::TABLE
                . " WHERE date = :date AND source = :source AND owner_uuid = '' AND event_type = ''";
            $raw['page_visits'] = $this->int("SELECT COUNT(DISTINCT session_id) {$pages}", $range);
            $raw['element_visits'] = $this->int("SELECT COUNT(DISTINCT ev.session_id) {$elements}", $range);
            $stored['page_visits'] = $this->int($site, $day + [':source' => VisitsAggregator::SOURCE_PAGES]);
            $stored['element_visits'] = $this->int($site, $day + [':source' => VisitsAggregator::SOURCE_ELEMENTS]);
        }

        return ['raw' => $raw, 'stored' => $stored];
    }

    private function int(string $sql, array $params): int
    {
        return (int)$this->db->createCommand($sql, $params)->queryScalar();
    }
}
```

- [ ] **Step 4: Rewrite `actionCleanup`**

Add `use giantbits\crelish\components\Analytics\AnalyticsDayCheck;` and `use giantbits\crelish\components\Analytics\DayVerifier;`. Replace the whole of `actionCleanup()`, `findUnaggregatedDays()` and `findGapDays()` with:

```php
    /**
     * Delete raw analytics data older than the retention period, whole days only.
     *
     * Every day due for deletion is first checked: its stored aggregates must be
     * at least its raw counts (page views, element views and, with the visits
     * table, site visits). A day that falls short is re-aggregated in repair
     * mode (only raising numbers) and checked again; a day still short keeps its
     * raw data, is reported on stderr, and the command exits non-zero.
     */
    public function actionCleanup()
    {
        $this->stdout("\n" . str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Analytics Data Cleanup\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n", Console::FG_CYAN);
        $this->stdout("Retention period: {$this->retentionDays} days\n");

        if ($this->dryRun) {
            $this->stdout("DRY RUN MODE - No changes will be made\n", Console::FG_YELLOW);
        }

        $db = Yii::$app->db;
        $firstKeptDay = AnalyticsRetention::firstKeptDay(date('Y-m-d'), (int)$this->retentionDays);
        $cutoff = $firstKeptDay . ' 00:00:00';
        $this->stdout("Raw data of days before {$firstKeptDay} is due for deletion\n\n");

        $days = AnalyticsRetention::daysToDelete($this->oldestRawDay($cutoff), $firstKeptDay);

        if ($this->skipAggregationCheck) {
            $this->stdout("⚠ Verification skipped (--skipAggregationCheck)\n\n", Console::FG_YELLOW);
            [$deletable, $kept] = [$days, []];
        } else {
            [$deletable, $kept] = $this->verifyDays($days);
        }

        if ($this->dryRun) {
            $this->stdout("Would delete the raw data of " . count($deletable) . " day(s) (dry run)\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if (!$this->confirmDestructive("Delete the raw data of " . count($deletable) . " day(s) and orphaned rows?")) {
            $this->stdout("Aborted\n");
            return ExitCode::OK;
        }

        $elementViews = 0;
        $pageViews = 0;
        foreach (AnalyticsRetention::ranges($deletable) as [$start, $end]) {
            $range = [':start' => $start, ':end' => $end];
            $elementViews += $this->deleteInBatches(
                "DELETE FROM {{%analytics_element_views}} WHERE created_at >= :start AND created_at < :end LIMIT :limit",
                $range
            );
            $pageViews += $this->deleteInBatches(
                "DELETE FROM {{%analytics_page_views}} WHERE created_at >= :start AND created_at < :end AND is_bot = 0 LIMIT :limit",
                $range
            );
        }
        $this->stdout("✓ Deleted " . number_format($elementViews) . " element view records\n", Console::FG_GREEN);
        $this->stdout("✓ Deleted " . number_format($pageViews) . " page view records\n", Console::FG_GREEN);

        // Orphaned element views are counted by no aggregate (all element
        // aggregation joins sessions), so removing them never affects a check.
        try {
            $deleted = $this->deleteOrphanedElementViews();
            $this->stdout("✓ Deleted " . number_format($deleted) . " orphaned element view records\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stderr("✗ Error deleting orphaned element views: " . $e->getMessage() . "\n", Console::FG_RED);
        }

        try {
            $deleted = $this->deleteInBatches(
                "DELETE FROM {{%analytics_sessions}}
                 WHERE created_at < :cutoff
                   AND NOT EXISTS (
                     SELECT 1 FROM {{%analytics_page_views}} pv
                     WHERE pv.session_id = {{%analytics_sessions}}.session_id
                   )
                 LIMIT :limit",
                [':cutoff' => $cutoff]
            );
            $this->stdout("✓ Deleted " . number_format($deleted) . " orphaned session records\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stderr("✗ Error deleting sessions: " . $e->getMessage() . "\n", Console::FG_RED);
        }

        if ($this->optimize) {
            $this->stdout("\nOptimizing tables...\n");
            foreach (['analytics_element_views', 'analytics_page_views', 'analytics_sessions'] as $table) {
                try {
                    $db->createCommand('OPTIMIZE TABLE ' . $db->quoteTableName($table))->execute();
                    $this->stdout("✓ Optimized {$table}\n", Console::FG_GREEN);
                } catch (\Exception $e) {
                    $this->stderr("✗ Error optimizing {$table}: " . $e->getMessage() . "\n", Console::FG_RED);
                }
            }
        } else {
            $this->stdout("\nSkipping OPTIMIZE TABLE (pass --optimize=1 to reclaim disk space)\n", Console::FG_YELLOW);
        }

        if ($kept !== []) {
            $this->stderr("\n✗ Raw data kept for " . count($kept) . " day(s) whose aggregates could not be completed: "
                . implode(', ', $kept) . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nCleanup completed successfully\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Check every day, repairing the ones that fall short (not in dry run).
     *
     * @param string[] $days
     * @return array{0: string[], 1: string[]} [days whose raw data may be deleted, days kept]
     */
    protected function verifyDays(array $days): array
    {
        $db = Yii::$app->db;
        $verifier = new DayVerifier($db, VisitsAggregator::tableExists($db));
        $shortfalls = static function (string $day) use ($verifier): array {
            $counts = $verifier->counts($day);
            return AnalyticsDayCheck::shortfalls($counts['stored'], $counts['raw']);
        };
        $deletable = [];
        $kept = [];
        $repaired = 0;

        foreach ($days as $day) {
            try {
                $short = $shortfalls($day);
                if ($short !== [] && !$this->dryRun) {
                    $this->stdout("  {$day}: aggregates below raw data (" . implode(', ', $short) . "), repairing\n", Console::FG_YELLOW);
                    $this->aggregateDate($day, AggregationParts::ALL, true);
                    $short = $shortfalls($day);
                    if ($short === []) {
                        $repaired++;
                    }
                }
            } catch (\Throwable $e) {
                $short = ['verification failed: ' . $e->getMessage()];
            }

            if ($short === []) {
                $deletable[] = $day;
                continue;
            }

            $kept[] = $day;
            $this->stderr("  {$day}: " . ($this->dryRun ? 'would repair' : 'kept, still below raw data')
                . ' (' . implode(', ', $short) . ")\n", Console::FG_RED);
        }

        $this->stdout(sprintf(
            "Checked %d day(s): %d may be deleted (%d after repair), %d kept\n\n",
            count($days), count($deletable), $repaired, count($kept)
        ));

        return [$deletable, $kept];
    }

    /**
     * Oldest raw timestamp before the cutoff, or null when there is none.
     */
    private function oldestRawDay(string $cutoff): ?string
    {
        $db = Yii::$app->db;
        $candidates = array_filter([
            $db->createCommand("SELECT MIN(created_at) FROM {{%analytics_page_views}} WHERE created_at < :cutoff", [':cutoff' => $cutoff])->queryScalar(),
            $db->createCommand("SELECT MIN(created_at) FROM {{%analytics_element_views}} WHERE created_at < :cutoff", [':cutoff' => $cutoff])->queryScalar(),
        ]);

        return $candidates === [] ? null : min($candidates);
    }
```

Search the repository for remaining callers and remove or update them:
`grep -rn "findUnaggregatedDays\|findGapDays" --include=*.php .` must print nothing outside `vendor/`.

- [ ] **Step 5: Run the tests**

Run: `CRELISH_TEST_MYSQL_DSN=… php tests/AnalyticsCleanupMysqlTest.php`, then the three earlier analytics tests.
Expected: all `0 failed`. Note: the bot-only day passes because its raw page views are bots (`is_bot = 1`, not reportable) and cleanup, as before, deletes only `is_bot = 0` page views, so the bot row stays (`rawPages($botsOnly)` = 1); what matters is that the day is not reported as kept and the exit code is 0.

- [ ] **Step 6: Commit**

```bash
git add components/Analytics/DayVerifier.php commands/AnalyticsAggregationController.php tests/AnalyticsCleanupMysqlTest.php
git commit -m "feat(analytics): cleanup checks each whole day against raw data and repairs before deleting"
```

---

### Task 6: `VisitsReader`

**Files:**
- Create: `components/Analytics/VisitsReader.php`
- Create: `tests/analytics/sqlite.php`
- Test: `tests/AnalyticsVisitsReaderTest.php`

**Interfaces:**
- Consumes: `VisitsAggregator::TABLE`, `::SOURCE_*`, `::tableExists` (Task 3).
- Produces: `new VisitsReader(?Connection $db = null)`, `->available(): bool`, `->summary(string $source, string $owner, string $start, string $end): array{available: bool, since: ?string, visits: ?int, users: ?int}`, `->byDay(string $source, string $owner, string $start, string $end): array<string, array{visits: int, users: int}>`, `->byMonth(...)` (keys `Y-m`, same shape). `byDay`/`byMonth` return `[]` when the table is missing.

- [ ] **Step 1: Write the SQLite harness**

`tests/analytics/sqlite.php`:

```php
<?php

/**
 * In-memory SQLite for the read-side tests (plain SELECT, SUM, GROUP BY).
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function analyticsSqliteApp(bool $withVisitsTable = true): \yii\console\Application
{
    $app = new \yii\console\Application([
        'id' => 'analytics-sqlite-test',
        'basePath' => dirname(__DIR__, 2),
        'components' => [
            'db' => [
                'class' => \yii\db\Connection::class,
                'dsn' => 'sqlite::memory:',
                'pdoClass' => class_exists(\Pdo\Sqlite::class) ? \Pdo\Sqlite::class : null,
            ],
        ],
    ]);

    if ($withVisitsTable) {
        $app->db->createCommand()->createTable('analytics_visits_daily', [
            'id' => 'integer PRIMARY KEY AUTOINCREMENT',
            'date' => 'date NOT NULL',
            'source' => 'varchar(16) NOT NULL',
            'owner_uuid' => "varchar(36) NOT NULL DEFAULT ''",
            'event_type' => "varchar(50) NOT NULL DEFAULT ''",
            'unique_sessions' => 'integer NOT NULL DEFAULT 0',
            'unique_users' => 'integer NOT NULL DEFAULT 0',
        ])->execute();
    }

    return $app;
}

function visitRow(string $date, string $source, string $owner, string $event, int $sessions, int $users = 0): void
{
    Yii::$app->db->createCommand()->insert('analytics_visits_daily', [
        'date' => $date, 'source' => $source, 'owner_uuid' => $owner, 'event_type' => $event,
        'unique_sessions' => $sessions, 'unique_users' => $users,
    ])->execute();
}
```

- [ ] **Step 2: Write the failing test**

`tests/AnalyticsVisitsReaderTest.php`:

```php
<?php

/**
 * Reading visits: totals, per day, per month, coverage of a period.
 *
 * Run with:  php tests/AnalyticsVisitsReaderTest.php
 */

declare(strict_types=1);

require __DIR__ . '/analytics/sqlite.php';

use giantbits\crelish\components\Analytics\VisitsReader;

const C1 = 'c1000000-0000-4000-8000-000000000001';

analyticsSqliteApp();
visitRow('2026-09-08', 'pages', '', '', 10, 1);
visitRow('2026-09-09', 'pages', '', '', 20, 2);
visitRow('2026-10-01', 'pages', '', '', 5, 0);
visitRow('2026-09-09', 'elements', '', '', 99);
visitRow('2026-09-09', 'elements', C1, '', 7);
visitRow('2026-09-09', 'elements', C1, 'detail', 4);

$reader = new VisitsReader();

echo "Totals\n";
check('site page visits over a covered period', ['available' => true, 'since' => null, 'visits' => 30, 'users' => 3],
    $reader->summary('pages', '', '2026-09-08', '2026-09-30'));
check('only the any-event row counts for an owner', 7, $reader->summary('elements', C1, '2026-09-01', '2026-09-30')['visits']);
check('a period before the first recorded day says from when', '2026-09-08', $reader->summary('pages', '', '2026-08-01', '2026-09-30')['since']);
check('nothing in range gives zero, not null', 0, $reader->summary('pages', '', '2026-09-20', '2026-09-25')['visits']);

echo "\nPer day and per month\n";
check('by day', ['2026-09-08' => ['visits' => 10, 'users' => 1], '2026-09-09' => ['visits' => 20, 'users' => 2]],
    $reader->byDay('pages', '', '2026-09-01', '2026-09-30'));
check('by month', ['2026-09' => ['visits' => 30, 'users' => 3], '2026-10' => ['visits' => 5, 'users' => 0]],
    $reader->byMonth('pages', '', '2026-09-01', '2026-10-31'));

echo "\nWithout the table\n";
analyticsSqliteApp(false);
$reader = new VisitsReader();
check('not available', false, $reader->available());
check('summary says so and has no figure', ['available' => false, 'since' => null, 'visits' => null, 'users' => null],
    $reader->summary('pages', '', '2026-09-01', '2026-09-30'));
check('by day is empty', [], $reader->byDay('pages', '', '2026-09-01', '2026-09-30'));

analyticsDone();
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php tests/AnalyticsVisitsReaderTest.php`
Expected: `Class "giantbits\crelish\components\Analytics\VisitsReader" not found`.

- [ ] **Step 4: Implement `VisitsReader`**

`components/Analytics/VisitsReader.php`:

```php
<?php

namespace giantbits\crelish\components\Analytics;

use Yii;
use yii\db\Connection;
use yii\db\Query;

/**
 * Visits for the statistics: distinct sessions per day, summed over days.
 * Never sums across owners or event types; only the any-event row ('') of
 * the requested owner is read.
 */
final class VisitsReader
{
    private Connection $db;
    private ?bool $available = null;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Yii::$app->db;
    }

    public function available(): bool
    {
        return $this->available ??= VisitsAggregator::tableExists($this->db);
    }

    /**
     * @return array{available: bool, since: ?string, visits: ?int, users: ?int}
     *         since: first recorded day when the period starts before it, else null
     */
    public function summary(string $source, string $owner, string $start, string $end): array
    {
        if (!$this->available()) {
            return ['available' => false, 'since' => null, 'visits' => null, 'users' => null];
        }

        $row = $this->query($source, $owner, $start, $end)
            ->select(['visits' => 'SUM(unique_sessions)', 'users' => 'SUM(unique_users)'])
            ->one($this->db);
        $first = (new Query())->from(VisitsAggregator::TABLE)->min('date', $this->db);

        return [
            'available' => true,
            'since' => ($first !== null && $first !== false && (string)$first > $start) ? (string)$first : null,
            'visits' => (int)($row['visits'] ?? 0),
            'users' => (int)($row['users'] ?? 0),
        ];
    }

    /**
     * @return array<string, array{visits: int, users: int}> keyed by Y-m-d
     */
    public function byDay(string $source, string $owner, string $start, string $end): array
    {
        return $this->grouped('date', $source, $owner, $start, $end);
    }

    /**
     * @return array<string, array{visits: int, users: int}> keyed by Y-m
     */
    public function byMonth(string $source, string $owner, string $start, string $end): array
    {
        return $this->grouped('SUBSTR(date, 1, 7)', $source, $owner, $start, $end);
    }

    private function grouped(string $key, string $source, string $owner, string $start, string $end): array
    {
        if (!$this->available()) {
            return [];
        }

        $out = [];
        $rows = $this->query($source, $owner, $start, $end)
            ->select(['period' => $key, 'visits' => 'SUM(unique_sessions)', 'users' => 'SUM(unique_users)'])
            ->groupBy([$key])
            ->orderBy([$key => SORT_ASC])
            ->all($this->db);

        foreach ($rows as $row) {
            $out[(string)$row['period']] = ['visits' => (int)$row['visits'], 'users' => (int)$row['users']];
        }

        return $out;
    }

    private function query(string $source, string $owner, string $start, string $end): Query
    {
        return (new Query())
            ->from(VisitsAggregator::TABLE)
            ->where(['source' => $source, 'owner_uuid' => $owner, 'event_type' => ''])
            ->andWhere(['>=', 'date', $start])
            ->andWhere(['<=', 'date', $end]);
    }
}
```

(`orderBy([$key => …])` with `SUBSTR(date, 1, 7)` as key: if Yii quotes it as a column name, pass `orderBy(new \yii\db\Expression($key . ' ASC'))` instead; the test catches either.)

- [ ] **Step 5: Run the test**

Run: `php tests/AnalyticsVisitsReaderTest.php`
Expected: `9 passed, 0 failed`.

- [ ] **Step 6: Commit**

```bash
git add components/Analytics/VisitsReader.php tests/analytics/sqlite.php tests/AnalyticsVisitsReaderTest.php
git commit -m "feat(analytics): VisitsReader for totals, days, months and coverage"
```

---

### Task 7: Admin statistics show visits

**Files:**
- Modify (CRLF): `controllers/AnalyticsAggregatedController.php` (`actionOverviewStats`, `actionPageViewsTrend`, `actionComparePeriods`, `getPeriodStats`)
- Modify (CRLF): `views/analytics-aggregated/index.twig` (KPI label + note, trend label)
- Modify (CRLF): `views/analytics-aggregated/element-performance.twig` (detail-only KPIs)
- Modify (LF): `messages/de/crelish.php`

**Interfaces:**
- Consumes: `VisitsReader::summary/byDay/byMonth`, `VisitsAggregator::SOURCE_PAGES` (Tasks 3, 6).
- Produces: JSON `overview-stats` gains `visits: {available: bool, since: ?string}`; `pageStats.total_sessions` / `total_users` are visits (null when unavailable); trend rows' `unique_sessions` / `unique_users` are visits or null; `compare-periods` `changes.unique_sessions` is null unless both periods are fully covered.

- [ ] **Step 1: Controller changes**

Add `use giantbits\crelish\components\Analytics\VisitsAggregator;` and `use giantbits\crelish\components\Analytics\VisitsReader;`.

In `actionOverviewStats()`, remove `'total_sessions' => 'SUM(unique_sessions)',` and `'total_users' => 'SUM(unique_users)',` from the page select, and directly after the `$pageStats = … ->one();` statement add:

```php
    // Visits per day for the whole site, summed over days; summing
    // unique_sessions over pages counted a visitor once per page seen.
    $visits = (new VisitsReader())->summary(VisitsAggregator::SOURCE_PAGES, '', $startDate, $endDate);
    $pageStats['total_sessions'] = $visits['visits'];
    $pageStats['total_users'] = $visits['users'];
```

and extend the return array with `'visits' => ['available' => $visits['available'], 'since' => $visits['since']],`.

In `actionPageViewsTrend()`, remove the `unique_sessions` and `unique_users` select entries from both queries, and replace `return $data;` with:

```php
    $reader = new VisitsReader();
    $visits = $useMonthly
      ? $reader->byMonth(VisitsAggregator::SOURCE_PAGES, '', $startDate, $endDate)
      : $reader->byDay(VisitsAggregator::SOURCE_PAGES, '', $startDate, $endDate);

    // Days before visits were recorded have no figure (null: a gap in the
    // chart) rather than the old sum over pages.
    foreach ($data as &$row) {
      $row['unique_sessions'] = $visits[$row['period']]['visits'] ?? null;
      $row['unique_users'] = $visits[$row['period']]['users'] ?? null;
    }
    unset($row);

    return $data;
```

In `getPeriodStats()`, remove `'unique_sessions' => 'SUM(unique_sessions)'` from the select and set the returned `unique_sessions` to:

```php
    $visits = (new VisitsReader())->summary(VisitsAggregator::SOURCE_PAGES, '', $startDate, $endDate);
    …
      // null unless the whole period has visit data, so a comparison never
      // sets recorded days against unrecorded ones
      'unique_sessions' => ($visits['available'] && $visits['since'] === null) ? $visits['visits'] : null,
```

In `actionComparePeriods()`, replace the `unique_sessions` change line with:

```php
        'unique_sessions' => ($stats1['unique_sessions'] === null || $stats2['unique_sessions'] === null)
          ? null
          : $this->calculatePercentageChange($stats2['unique_sessions'], $stats1['unique_sessions']),
```

- [ ] **Step 2: Overview view**

In `views/analytics-aggregated/index.twig`:
1. Line with `{{ t('crelish', 'Unique Sessions') }}` in the KPI card → `{{ t('crelish', 'Visits') }}`; directly after the `kpi-sessions` value div add `<div class="kpi-note text-muted small" id="kpi-sessions-note"></div>`.
2. Where `kpi-sessions` is filled (`formatNumber(data.pageStats.total_sessions || 0)`), replace with:

```js
                const sessionsNote = document.getElementById('kpi-sessions-note');
                if (!data.visits.available) {
                    document.getElementById('kpi-sessions').textContent = '–';
                    sessionsNote.textContent = '{{ t("crelish", "Visits not recorded yet") }}';
                } else {
                    document.getElementById('kpi-sessions').textContent = formatNumber(data.pageStats.total_sessions || 0);
                    sessionsNote.textContent = data.visits.since
                        ? '{{ t("crelish", "Visits counted from {date}") }}'.replace('{date}', new Date(data.visits.since).toLocaleDateString('de-CH'))
                        : '';
                }
```

3. The trend dataset label `'{{ t("crelish", "Unique Sessions") }}'` → `'{{ t("crelish", "Visits") }}'`.

- [ ] **Step 3: Element detail view**

In `views/analytics-aggregated/element-performance.twig`, change the KPI labels `Unique Sessions` → `Visits (detail view)` and `Unique Users` → `Logged-in users (detail view)`, remove `let totalSessions = 0;`, `let totalUsers = 0;` and the two lines adding to them, and replace the two KPI assignments with:

```js
                // Visits cannot be added up across event types (one visitor in the
                // list and in the detail view would count twice), so show the detail view's
                const detailTotals = eventTypeTotals['detail'] || {sessions: 0, users: 0};
                document.getElementById('kpi-unique-sessions').textContent = formatNumber(detailTotals.sessions);
                document.getElementById('kpi-unique-users').textContent = formatNumber(detailTotals.users);
```

- [ ] **Step 4: German strings**

In `messages/de/crelish.php`, before the closing `];`, add:

```php
  'Visits' => 'Besuche',
  'Visits (detail view)' => 'Besuche (Detailansicht)',
  'Logged-in users (detail view)' => 'Angemeldete Nutzer (Detailansicht)',
  'Visits counted from {date}' => 'Besuche erfasst ab {date}',
  'Visits not recorded yet' => 'Besuche noch nicht erfasst',
```

- [ ] **Step 5: Verify**

Run: `php -l controllers/AnalyticsAggregatedController.php && php -l messages/de/crelish.php && git diff --stat` (only the edited lines per file; CRLF intact: `grep -c $'\r$'` equals `wc -l` for the controller and both twig files).
Run the existing tests that touch the admin: `php tests/AccessControlTest.php && php tests/MessageSourceTest.php` — both `0 failed`.
Manual check in a browser comes in Task 10.

- [ ] **Step 6: Commit**

```bash
git add controllers/AnalyticsAggregatedController.php views/analytics-aggregated/index.twig views/analytics-aggregated/element-performance.twig messages/de/crelish.php
git commit -m "feat(analytics): admin statistics show visits instead of sessions summed over pages"
```

---

### Task 8: Company reports show visits

**Files:**
- Modify (CRLF): `controllers/CompanyAnalyticsController.php` (`actionOverviewStats`, `actionTrends`, `getOverviewStatsData`, `actionExportPdf` if it passes coverage to the template)
- Modify (CRLF): `views/company-analytics/index.twig` (KPI label + note, trend label)
- Modify (CRLF): `views/company-analytics/_pdf-report.twig` (KPI label + note)

**Interfaces:**
- Consumes: `VisitsReader::summary/byDay`, `VisitsAggregator::SOURCE_ELEMENTS` (Tasks 3, 6).
- Produces: company `overview-stats` JSON: `unique_sessions` = company visits (null when unavailable) and `visits: {available, since}`; `trends` rows: `unique_sessions` = company visits that day or null; PDF `overviewStats.unique_sessions` / `overviewStats.visits` likewise.

- [ ] **Step 1: Controller changes**

Add the two `use` lines from Task 7. Add a private helper:

```php
    /**
     * The company's visits: sessions that saw any of its content, per day,
     * summed over days. Read from the owner rows (page_uuid = company).
     *
     * @return array{available: bool, since: ?string, visits: ?int, users: ?int}
     */
    private function companyVisits(string $companyUuid, string $startDate, string $endDate): array
    {
        return (new VisitsReader())->summary(VisitsAggregator::SOURCE_ELEMENTS, $companyUuid, $startDate, $endDate);
    }
```

In `actionOverviewStats()` and `getOverviewStatsData()`, remove `'unique_sessions' => 'SUM(unique_sessions)',` from the select and, after the stats row is fetched, add:

```php
        $visits = $this->companyVisits($companyUuid, $startDate, $endDate);
        $stats['unique_sessions'] = $visits['visits'];
        $stats['visits'] = ['available' => $visits['available'], 'since' => $visits['since']];
```

(In `getOverviewStatsData()` the fetched row is returned directly today; assign it to `$stats` first, add the lines, then `return $stats;`.)

In `actionTrends()`, remove `'unique_sessions' => 'SUM(unique_sessions)',` and replace the final return with:

```php
        $rows = $this->applyCompanyFilter($query, $companyUuid)->all();
        $visits = (new VisitsReader())->byDay(VisitsAggregator::SOURCE_ELEMENTS, $companyUuid, $startDate, $endDate);

        foreach ($rows as &$row) {
            $row['unique_sessions'] = $visits[$row['date']]['visits'] ?? null;
        }
        unset($row);

        return $rows;
```

- [ ] **Step 2: Web report view**

In `views/company-analytics/index.twig`: KPI label `Unique Sessions` → `Visits`; after the `kpi-sessions` value add `<div class="kpi-note text-muted small" id="kpi-sessions-note"></div>`; replace the `kpi-sessions` assignment with the same block as Task 7 Step 2.2 (using `data.unique_sessions` and `data.visits`); the trend dataset label `Unique Sessions` → `Visits`.

- [ ] **Step 3: PDF report**

In `views/company-analytics/_pdf-report.twig` replace the KPI value and label:

```twig
                <div class="kpi-value">{{ overviewStats.visits.available ? overviewStats.unique_sessions|default(0)|number_format(0, ',', '.') : '–' }}</div>
                <div class="kpi-label">{{ t('crelish', 'Visits') }}</div>
                {% if not overviewStats.visits.available %}
                <div class="kpi-label">{{ t('crelish', 'Visits not recorded yet') }}</div>
                {% elseif overviewStats.visits.since %}
                <div class="kpi-label">{{ t('crelish', 'Visits counted from {date}')|replace({'{date}': overviewStats.visits.since|date('d.m.Y')}) }}</div>
                {% endif %}
```

(Check the existing KPI markup around line 64 and keep its wrapper; only value, label and the note change.)

- [ ] **Step 4: Verify**

Run: `php -l controllers/CompanyAnalyticsController.php`; `git diff --stat` shows only edited lines; CRLF intact in all three files. The rendered PDF and web report are checked in Task 10.

- [ ] **Step 5: Commit**

```bash
git add controllers/CompanyAnalyticsController.php views/company-analytics/index.twig views/company-analytics/_pdf-report.twig
git commit -m "feat(analytics): company reports show visits, counted once per visitor and day"
```

---

### Task 9: Documentation

**Files:**
- Modify: `docs/analytics-aggregation.md`

- [ ] **Step 1: Update the docs**

1. In `### Tables`, add a row/paragraph for `analytics_visits_daily`: one row per day, source (`pages`/`elements`), owner (`''` = site, else the element views' `page_uuid`, for jobs the company) and event type (`''` = any); holds distinct sessions/users; reports sum it over days, never across owners or event types.
2. In `### 1. Run Migration`, add `php yii crelish-migrate/up` for `m261008_120000_create_analytics_visits_daily` and note that `dep deploy` runs only `yii migrate`, so crelish migrations run by hand.
3. In `### 4. Initial Backfill` and `### Backfill`, document `--only` (`pages,elements,visits`), `--pagesOnly=1` as alias, and the rollout command `php yii crelish/analytics-aggregation/backfill 29 --only=visits`; warn: never recompute `elements` for days older than a few days (the cleanup thins orphaned element views; recomputing lowers correct counts).
4. Replace `### Cleanup Old Data` with: whole days only; each day checked (stored ≥ raw for page views, element views, site visits); short days repaired with `GREATEST`, kept and reported (exit code 1) when still short; `--dryRun` shows the per-day result; `--skipAggregationCheck=1` deletes without checking.
5. Add `## Visits` after `## Period Options`: definition (a visitor counts once per day, summed over the period), why `unique_sessions` must not be summed across pages/elements/event types, coverage note ("Besuche erfasst ab …") for periods starting before the first recorded day.

- [ ] **Step 2: Commit**

```bash
git add docs/analytics-aggregation.md
git commit -m "docs(analytics): visits, --only and the verifying cleanup"
```

---

### Task 10: Verification on real data, release 0.25.0, rollout to forum-holzkarriere

**Files:**
- Modify: `composer.json` (version)

- [ ] **Step 1: Full test run**

Run every analytics test plus the existing suite:
`for t in tests/*Test.php; do php "$t" > /tmp/out.txt 2>&1 || { echo "FAIL $t"; tail -20 /tmp/out.txt; }; done` (with the `CRELISH_TEST_MYSQL_*` variables set).
Expected: no `FAIL` line.

- [ ] **Step 2: Real-data check on the local forum-holzkarriere copy**

The local project uses this checkout through a symlink. On the local database (raw data back to 2025-04):
1. `php yii crelish-migrate/up` (local), then `php yii crelish/analytics-aggregation/backfill 29 --only=visits`.
2. For 5 sample days and 3 companies with the most job views, compare `analytics_visits_daily` against `COUNT(DISTINCT session_id)` computed directly from raw data with the same filters. Expected: equal.
3. Record checksums (`COUNT(*)`, `SUM(CRC32(CONCAT_WS('|', …)))`) of `analytics_page_daily` and `analytics_element_daily`; run `php yii crelish/analytics-aggregation/cleanup --dryRun=1` and read the per-day results; run it for real with `--retentionDays=400` (deletes only the oldest weeks); rerun; compare: no aggregate row's counts may be lower than before (`SELECT … WHERE new < old` returns nothing), and the second run reports nothing to repair.
4. Run the full nightly sequence once (`bot-detection/index`, `daily`, `cleanup`) and compare page/element totals for the last 30 days before and after: unchanged except for the newest day.
5. Open the admin statistics and one company report (web and PDF) in the browser: KPI "Besuche", the "Besuche erfasst ab …" note for a period starting before the backfilled range, no console errors.

- [ ] **Step 3: Release 0.25.0**

```bash
git checkout -b release/0.25.0 develop
sed -i '' 's/"version": "0.24.2"/"version": "0.25.0"/' composer.json
git commit -am "Bump version to 0.25.0"
git checkout master && git merge --no-ff release/0.25.0 -m "Merge branch 'release/0.25.0'"
git tag -a 0.25.0 -m "0.25.0"
git checkout develop && git merge --no-ff release/0.25.0 -m "Merge branch 'release/0.25.0' into develop"
git branch -d release/0.25.0
git push origin master develop 0.25.0
```

Then from the production host, wait until Packagist serves 0.25.0:
`curl -s "https://repo.packagist.org/p2/giantbits/yii2-crelish.json?cb=$RANDOM" | grep -o '"version":"[^"]*"' | head -1` → `"version":"0.25.0"`.

- [ ] **Step 4: Rollout forum-holzkarriere**

1. `./vendor/bin/dep deploy` in the project; confirm `current/composer.lock` has crelish 0.25.0 and the deploy printed `opcache reset … "reset":true`.
2. On production: `php yii crelish-migrate/new` must list exactly `m261008_120000_create_analytics_visits_daily`; then `php yii crelish-migrate/up --interactive=0`.
3. `php yii crelish/analytics-aggregation/backfill 29 --only=visits --interactive=0`; exit code 0. (29, not 30: the last 0.24 cleanup cut the 30th day in two; the first recorded day may still be partial.)
4. Verify every day against raw data as in Step 2.2 (site rows and the 3 largest companies).
5. `php yii crelish/analytics-aggregation/cleanup --dryRun=1`: every day passes or is repaired, none kept.
6. A person checks one company report in the admin (web and PDF).
7. The next morning, read `runtime/logs/analytics.log`: the nightly run includes "visit records", the cleanup reports the checked days, exit codes 0.
