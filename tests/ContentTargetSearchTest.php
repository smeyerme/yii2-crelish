<?php

/**
 * Target search shared by the menu editor and short links.
 *
 * Run with:  php tests/ContentTargetSearchTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\ContentTargetSearch;

class SearchPage extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return 'page';
    }
}

$app = shortLinkApp(['detailPages' => ['news' => 'news']]);
$app->db->createCommand()->createTable('page', ['uuid' => 'varchar(36) PRIMARY KEY', 'systitle' => 'varchar(255)'])->execute();
foreach (['Akademie', 'Aktuelles', 'Bauten'] as $i => $title) {
    $app->db->createCommand()->insert('page', ['uuid' => "p$i", 'systitle' => $title])->execute();
}
for ($i = 0; $i < 25; $i++) {
    $app->db->createCommand()->insert('page', ['uuid' => "x$i", 'systitle' => sprintf('Xylo %02d', $i)])->execute();
}
$classFor = fn(string $ctype) => SearchPage::class;

echo "Search\n";
check('matches systitle, sorted', [['uuid' => 'p0', 'title' => 'Akademie'], ['uuid' => 'p1', 'title' => 'Aktuelles']], ContentTargetSearch::search('page', 'Ak', $classFor));
check('query is trimmed', 1, count(ContentTargetSearch::search('page', '  Bau ', $classFor)));
check('one character is too short', [], ContentTargetSearch::search('page', 'A', $classFor));
check('limit 20', 20, count(ContentTargetSearch::search('page', 'Xylo', $classFor)));
check('unresolvable type', [], ContentTargetSearch::search('sponsor', 'Ak', $classFor));
check('errors degrade to empty', [], ContentTargetSearch::search('page', 'Ak', fn() => throw new RuntimeException('no model')));

echo "\nTypes\n";
check('types with labels', ['news', 'page'], array_column(ContentTargetSearch::types(), 'ctype'));
check('label falls back to ctype', 'News', ContentTargetSearch::types()[0]['label']);

$dir = sys_get_temp_dir() . '/crelish-types-' . uniqid();
mkdir($dir . '/workspace/elements', 0777, true);
file_put_contents($dir . '/workspace/elements/news.json', json_encode(['label' => 'Neuigkeiten']));
$originalApp = Yii::getAlias('@app', false);
Yii::setAlias('@app', $dir);
try {
    $labels = array_column(ContentTargetSearch::types(), 'label', 'ctype');
    check('label read from the element definition', 'Neuigkeiten', $labels['news']);
    check('type without a definition keeps the fallback', 'Page', $labels['page']);
} finally {
    Yii::setAlias('@app', $originalApp === false ? null : $originalApp);
    unlink($dir . '/workspace/elements/news.json');
    rmdir($dir . '/workspace/elements');
    rmdir($dir . '/workspace');
    rmdir($dir);
}
check('@app alias restored', $originalApp, Yii::getAlias('@app', false));

shortLinkDone();
