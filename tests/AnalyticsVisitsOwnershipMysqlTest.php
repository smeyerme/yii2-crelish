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
