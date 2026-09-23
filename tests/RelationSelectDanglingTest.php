<?php

/**
 * Regression tests for relations that point at a record which no longer exists.
 *
 * Ein Verweis ins Leere ist Alltag: die verknüpfte Region wird gelöscht, ein
 * Import schreibt einen Klartextnamen statt einer uuid. CrelishDataResolver gab
 * dafür bisher ein leeres CrelishDynamicJsonModel zurück, dessen init() die
 * uuid seinerseits nullt. Alle Aufrufer prüfen das Ergebnis auf falsy, also
 * lief dieses hohle Objekt ungehindert bis in die Formulare — und
 * normalizeToArray() castete es dort auf string, was die Seite mit einem
 * fatalen Fehler abbrach.
 *
 * Zwei Zusagen müssen halten: resolveModel() liefert bei Fehltreffer null, und
 * normalizeToArray() verträgt trotzdem jedes Objekt, das keine uuid hergibt.
 *
 * Run with:  php tests/RelationSelectDanglingTest.php
 */

declare(strict_types=1);

define('YII_DEBUG', false);
define('YII_ENV', 'test');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use giantbits\crelish\components\CrelishDataResolver;
use giantbits\crelish\components\CrelishDynamicJsonModel;
use giantbits\crelish\plugins\relationselect\RelationSelect;
use giantbits\crelish\plugins\relationselect\RelationSelectContentProcessor;

const REAL_UUID = '379dafa6-5fc4-4157-9a37-cb37ca18cb88';
const GONE_UUID = '00000000-0000-4000-8000-000000000000';
const PLAIN_TEXT = 'Niederösterreich';

// ---------------------------------------------------------------- test bed --

$root = sys_get_temp_dir() . '/crelish-relation-test-' . getmypid();
@mkdir($root . '/workspace/elements', 0777, true);
@mkdir($root . '/workspace/data/guardregion', 0777, true);

file_put_contents($root . '/workspace/elements/guardregion.json', json_encode([
    'key' => 'guardregion',
    'label' => 'Region',
    'storage' => 'json',
    'fields' => [
        ['label' => 'Title', 'key' => 'systitle', 'type' => 'textInput'],
    ],
]));

file_put_contents(
    $root . '/workspace/data/guardregion/' . REAL_UUID . '.json',
    json_encode(['uuid' => REAL_UUID, 'systitle' => 'Vorarlberg'])
);

Yii::setAlias('@app', $root);

new yii\console\Application([
    'id' => 'crelish-relation-test',
    'basePath' => $root,
    'language' => 'de',
    'components' => [],
    'params' => ['crelish' => ['languages' => ['de']]],
]);

/** Feldkonfiguration, wie sie aus der Element-Definition kommt. */
$field = static fn(bool $multiple): stdClass => json_decode(json_encode([
    'key' => 'region',
    'label' => 'Region',
    'type' => 'relationSelect',
    'config' => ['ctype' => 'guardregion', 'multiple' => $multiple],
]));

/** normalizeToArray() ist privat und init() bräuchte den AssetManager. */
$normalize = static function ($value) {
    $widget = (new ReflectionClass(RelationSelect::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(RelationSelect::class, 'normalizeToArray');
    return $method->invoke($widget, $value);
};

$failures = 0;

/** var_export() auf einem Modell flutet die Ausgabe — Klassenname genügt. */
$show = static function ($value) use (&$show): string {
    if (is_object($value)) {
        return get_class($value) . '(uuid: ' . var_export($value->uuid ?? null, true) . ')';
    }
    if (is_array($value)) {
        return '[' . implode(', ', array_map($show, $value)) . ']';
    }
    return var_export($value, true);
};

$assert = static function (string $what, $expected, $actual) use (&$failures, $show): void {
    if ($expected === $actual) {
        echo "  ok   $what\n";
        return;
    }
    $failures++;
    echo "  FAIL $what\n";
    echo '         expected: ' . $show($expected) . "\n";
    echo '         actual:   ' . $show($actual) . "\n";
};

$outcome = static function (callable $fn) {
    try {
        return $fn();
    } catch (\Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }
};

// ------------------------------------------------------------------- tests --

echo "CrelishDataResolver::resolveModel()\n";

$resolved = CrelishDataResolver::resolveModel(['ctype' => 'guardregion', 'uuid' => REAL_UUID]);
$assert('a uuid that identifies a record resolves', REAL_UUID, $resolved->uuid ?? null);

$assert(
    'a uuid with no record resolves to null',
    null,
    CrelishDataResolver::resolveModel(['ctype' => 'guardregion', 'uuid' => GONE_UUID])
);

$assert(
    'a plain-text value instead of a uuid resolves to null',
    null,
    CrelishDataResolver::resolveModel(['ctype' => 'guardregion', 'uuid' => PLAIN_TEXT])
);

echo "RelationSelectContentProcessor::processData()\n";

$processed = [];
RelationSelectContentProcessor::processData('region', REAL_UUID, $processed, $field(false));
$assert('a live relation is hydrated', REAL_UUID, $processed['region']->uuid ?? null);

$processed = [];
RelationSelectContentProcessor::processData('region', GONE_UUID, $processed, $field(false));
$assert('a dangling relation stays null', null, $processed['region']);

$processed = [];
RelationSelectContentProcessor::processData(
    'region',
    [REAL_UUID, GONE_UUID],
    $processed,
    $field(true)
);
$assert('a dangling entry drops out of a multiple relation', 1, count($processed['region']));

echo "RelationSelect::normalizeToArray()\n";

$assert('a uuid string normalises to itself', [REAL_UUID], $outcome(fn() => $normalize(REAL_UUID)));

$assert(
    'a hydrated model normalises to its uuid',
    [REAL_UUID],
    $outcome(fn() => $normalize(new CrelishDynamicJsonModel([], [
        'ctype' => 'guardregion',
        'uuid' => REAL_UUID,
    ])))
);

// Der Fall aus Sentry: das hohle Modell erreicht das Formular und wird dort
// auf string gecastet — "Object of class CrelishDynamicJsonModel could not be
// converted to string", mitten im Rendern des Firmenprofils.
$assert(
    'an object without a uuid is dropped, not cast to string',
    [],
    $outcome(fn() => $normalize(new CrelishDynamicJsonModel([], [
        'ctype' => 'guardregion',
        'uuid' => GONE_UUID,
    ])))
);

$assert(
    'an object without a uuid inside an array is dropped too',
    [REAL_UUID],
    $outcome(fn() => $normalize([
        REAL_UUID,
        new CrelishDynamicJsonModel([], ['ctype' => 'guardregion', 'uuid' => GONE_UUID]),
    ]))
);

// ----------------------------------------------------------------- cleanup --

array_map('unlink', glob($root . '/workspace/data/guardregion/*.json') ?: []);
array_map('unlink', glob($root . '/workspace/elements/*.json') ?: []);
@rmdir($root . '/workspace/data/guardregion');
@rmdir($root . '/workspace/data');
@rmdir($root . '/workspace/elements');
@rmdir($root . '/workspace');
@rmdir($root);

echo $failures === 0 ? "\nall assertions passed\n" : "\n$failures assertion(s) failed\n";
exit($failures === 0 ? 0 : 1);
