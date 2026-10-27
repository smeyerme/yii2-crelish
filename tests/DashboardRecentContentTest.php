<?php

/**
 * The dashboard's "recent content" list reads uuid and systitle, the columns crelish
 * tables actually have. It selected id and title, which failed for every content type
 * and logged one warning per type on each dashboard visit (FORUM-HOLZBRANCHE-9).
 * Types without a table (JSON storage) or without the columns are skipped quietly.
 *
 * The statistics likewise skip types without a table (FORUM-HOLZBAU-4S).
 *
 * Run with:  php tests/DashboardRecentContentTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\controllers\DashboardController;
use yii\db\Connection;

$db = new Connection(['dsn' => 'sqlite::memory:']);
$db->createCommand('CREATE TABLE product (uuid TEXT, systitle TEXT, updated INTEGER)')->execute();
$db->createCommand("INSERT INTO product VALUES ('p1', 'Leimholz', 200), ('p2', 'Brettschichtholz', 100), ('p3', 'Ohne Datum', NULL)")->execute();
$db->createCommand('CREATE TABLE branch (uuid TEXT, systitle TEXT, updated INTEGER)')->execute();
$db->createCommand("INSERT INTO branch VALUES ('b1', 'Sägewerke', 300)")->execute();
$db->createCommand('CREATE TABLE legacy (id INTEGER, title TEXT)')->execute();

$logger = \Yii::getLogger();

$types = [
    'product' => ['label' => 'Produkt'],
    'branch' => ['label' => 'Branche'],
    'legacy' => ['label' => 'Alt'],
    'jsononly' => ['label' => 'Nur JSON'],
];

$before = count(array_filter($logger->messages, fn($m) => $m[1] === \yii\log\Logger::LEVEL_WARNING));
$items = DashboardController::recentContent($types, 10, $db);
$after = count(array_filter($logger->messages, fn($m) => $m[1] === \yii\log\Logger::LEVEL_WARNING));

check('newest first, items without date left out', ['b1', 'p1', 'p2'], array_column($items, 'id'));
check('title from systitle', 'Sägewerke', $items[0]['title']);
check('type and label', ['branch', 'Branche'], [$items[0]['type'], $items[0]['typeLabel']]);
check('limit applies across types', ['b1', 'p1'], array_column(DashboardController::recentContent($types, 2, $db), 'id'));
check('no warnings for missing tables or columns', 0, $after - $before);

// FORUM-HOLZBAU-4S: the statistics counted tables that do not exist
$before = count(array_filter($logger->messages, fn($m) => $m[1] === \yii\log\Logger::LEVEL_WARNING));
$stats = DashboardController::contentStatistics($types, $db);
$after = count(array_filter($logger->messages, fn($m) => $m[1] === \yii\log\Logger::LEVEL_WARNING));
check('statistics for types with a table', ['product' => 3, 'branch' => 1, 'legacy' => 0], array_map(fn($s) => (int)$s['count'], $stats));
check('no warnings for types without a table', 0, $after - $before);

shortLinkDone();
