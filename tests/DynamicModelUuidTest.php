<?php

/**
 * Regression tests for the uuid a CrelishDynamicModel ends up carrying.
 *
 * The uuid reaches the model straight from the URL. For json-backed elements a
 * lookup miss always nulled it, so `$model->uuid` could only ever hold a value
 * that really exists on disk. The db-backed branch did not: a uuid matching no
 * row was kept verbatim, which left arbitrary request input sitting in a public
 * property that other code interpolates into markup and storage keys.
 *
 * Both branches must agree: a uuid survives only if it identifies a record.
 *
 * Run with:  php tests/DynamicModelUuidTest.php
 */

declare(strict_types=1);

namespace {

    define('YII_DEBUG', false);
    define('YII_ENV', 'test');

    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

    const REAL_UUID = '379dafa6-5fc4-4157-9a37-cb37ca18cb88';
    const XSS_UUID = '"><script>alert(1)</script>';

    /**
     * Stand-in for a found row: read like an array, asked for hasMethod().
     */
    final class StubRecord implements \ArrayAccess
    {
        public function __construct(private array $data)
        {
        }

        public function hasMethod($name): bool
        {
            return false;
        }

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->data[$offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->data[$offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->data[$offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->data[$offset]);
        }
    }

    /**
     * Stand-in for the query the resolved model class hands back. Only what
     * loadModelData() touches: where()->one(), answering from a fixed table.
     */
    final class StubQuery
    {
        /** @var array<string, array<string, mixed>> */
        public static array $rows = [];

        private ?StubRecord $match = null;

        public function where(array $condition): self
        {
            $uuid = $condition['uuid'] ?? null;
            $row = is_string($uuid) ? (self::$rows[$uuid] ?? null) : null;
            $this->match = $row === null ? null : new StubRecord($row);
            return $this;
        }

        public function one(): ?StubRecord
        {
            return $this->match;
        }
    }
}

namespace app\workspace\models {

    /**
     * Resolved by CrelishModelResolver's legacy ucfirst() fallback for ctype
     * "guardprobe". Not an ActiveRecord — loadModelData() only calls find().
     */
    final class Guardprobe
    {
        public static function find(): \StubQuery
        {
            return new \StubQuery();
        }
    }
}

namespace {

    use giantbits\crelish\components\CrelishDynamicModel;

    // ------------------------------------------------------------ test bed --

    $root = sys_get_temp_dir() . '/crelish-uuid-test-' . getmypid();
    @mkdir($root . '/workspace/elements', 0777, true);
    @mkdir($root . '/workspace/data/guardfile', 0777, true);

    $definition = static fn(string $storage): string => json_encode([
        'key' => 'probe',
        'label' => 'Probe',
        'storage' => $storage,
        'fields' => [
            ['label' => 'Title', 'key' => 'systitle', 'type' => 'textInput'],
        ],
    ]);

    file_put_contents($root . '/workspace/elements/guardprobe.json', $definition('db'));
    file_put_contents($root . '/workspace/elements/guardfile.json', $definition('json'));
    file_put_contents(
        $root . '/workspace/data/guardfile/' . REAL_UUID . '.json',
        json_encode(['uuid' => REAL_UUID, 'systitle' => 'On disk'])
    );

    \Yii::setAlias('@app', $root);

    new \yii\console\Application([
        'id' => 'crelish-uuid-test',
        'basePath' => $root,
        'language' => 'de',
        'components' => [],
        'params' => ['crelish' => ['languages' => ['de']]],
    ]);

    StubQuery::$rows = [REAL_UUID => ['uuid' => REAL_UUID, 'systitle' => 'In database']];

    // --------------------------------------------------------------- tests --

    $failures = 0;
    $assert = static function (string $what, $expected, $actual) use (&$failures): void {
        if ($expected === $actual) {
            echo "  ok   $what\n";
            return;
        }
        $failures++;
        echo "  FAIL $what\n";
        echo '         expected: ' . var_export($expected, true) . "\n";
        echo '         actual:   ' . var_export($actual, true) . "\n";
    };

    echo "db-backed element\n";

    $model = new CrelishDynamicModel(['ctype' => 'guardprobe', 'uuid' => REAL_UUID]);
    $assert('a uuid that identifies a row survives', REAL_UUID, $model->uuid);

    $model = new CrelishDynamicModel(['ctype' => 'guardprobe', 'uuid' => XSS_UUID]);
    $assert('markup smuggled in as a uuid is dropped', null, $model->uuid);

    $model = new CrelishDynamicModel([
        'ctype' => 'guardprobe',
        'uuid' => '00000000-0000-4000-8000-000000000000',
    ]);
    $assert('a well-formed uuid with no row is dropped', null, $model->uuid);

    echo "json-backed element (the contract being mirrored)\n";

    $model = new CrelishDynamicModel(['ctype' => 'guardfile', 'uuid' => REAL_UUID]);
    $assert('a uuid that identifies a file survives', REAL_UUID, $model->uuid);

    $model = new CrelishDynamicModel(['ctype' => 'guardfile', 'uuid' => XSS_UUID]);
    $assert('markup smuggled in as a uuid is dropped', null, $model->uuid);

    echo "deleting a record that was never loaded\n";

    // The storage layer takes the uuid as a string. Now that a miss nulls it,
    // delete() must refuse rather than hand null down the chain — under the
    // console error handler that deprecation is promoted to a fatal.
    $model = new CrelishDynamicModel(['ctype' => 'guardfile', 'uuid' => XSS_UUID]);
    $deleted = null;
    try {
        $deleted = $model->delete();
    } catch (\Throwable $e) {
        $deleted = get_class($e) . ': ' . $e->getMessage();
    }
    $assert('delete() refuses instead of passing null to storage', false, $deleted);

    // ------------------------------------------------------------- cleanup --

    array_map('unlink', glob($root . '/workspace/data/guardfile/*.json') ?: []);
    array_map('unlink', glob($root . '/workspace/elements/*.json') ?: []);
    @rmdir($root . '/workspace/data/guardfile');
    @rmdir($root . '/workspace/data');
    @rmdir($root . '/workspace/elements');
    @rmdir($root . '/workspace');
    @rmdir($root);

    echo $failures === 0 ? "\nall assertions passed\n" : "\n$failures assertion(s) failed\n";
    exit($failures === 0 ? 0 : 1);
}
