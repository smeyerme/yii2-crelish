<?php

/**
 * The legacy sort format ['by' => ...] of the JSON storage era becomes column => direction,
 * so the database storage no longer orders by a column named "by".
 *
 * Run with:  php tests/LegacySortTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CrelishDataManager;

check('[field, asc]', ['systitle' => SORT_ASC], CrelishDataManager::normalizeSort(['by' => ['systitle', 'asc']]));
check('[field, desc]', ['created' => SORT_DESC], CrelishDataManager::normalizeSort(['by' => ['created', 'desc']]));
check('[field] without direction', ['systitle' => SORT_ASC], CrelishDataManager::normalizeSort(['by' => ['systitle']]));
check('[field => SORT_ASC]', ['systitle' => SORT_ASC], CrelishDataManager::normalizeSort(['by' => ['systitle' => SORT_ASC]]));
check('[field => SORT_DESC]', ['created' => SORT_DESC], CrelishDataManager::normalizeSort(['by' => ['created' => SORT_DESC]]));
check('by string with dir', ['created' => SORT_DESC], CrelishDataManager::normalizeSort(['by' => 'created', 'dir' => 'desc']));
check('by string without dir', ['created' => SORT_ASC], CrelishDataManager::normalizeSort(['by' => 'created']));
check('inside defaultOrder', ['defaultOrder' => ['systitle' => SORT_ASC]], CrelishDataManager::normalizeSort(['defaultOrder' => ['by' => ['systitle', 'asc']]]));
check('current format unchanged', ['systitle' => SORT_ASC, 'created' => SORT_DESC], CrelishDataManager::normalizeSort(['systitle' => SORT_ASC, 'created' => SORT_DESC]));
check('defaultOrder unchanged', ['defaultOrder' => ['systitle' => SORT_DESC]], CrelishDataManager::normalizeSort(['defaultOrder' => ['systitle' => SORT_DESC]]));
check('unusable by is dropped', [], CrelishDataManager::normalizeSort(['by' => 42]));

shortLinkDone();
