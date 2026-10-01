<?php

/**
 * The admin edits and saves the default-language columns of a translated
 * db-backed element, whatever the admin UI language; the frontend keeps
 * seeing the translated values.
 *
 * Covers CrelishDynamicModel::loadModelData() (db branch) and
 * CrelishDbStorage::save() (update path) with a model generated the way
 * ContentTypeController does it (CrelishTranslationBehavior attached).
 *
 * Run with:  php tests/TranslationAdminPathTest.php
 */

declare(strict_types=1);

namespace {
    require __DIR__ . '/menu/bootstrap.php';
}

namespace app\workspace\models {

    /**
     * Resolved by CrelishModelResolver's legacy ucfirst() fallback for ctype "wbprobe"
     */
    class Wbprobe extends \yii\db\ActiveRecord
    {
        public static function tableName(): string
        {
            return 'wbprobe';
        }

        public function behaviors(): array
        {
            return [
                \giantbits\crelish\components\CrelishTranslationBehavior::class
            ];
        }
    }
}

namespace {

    use giantbits\crelish\components\CrelishBaseController;
    use giantbits\crelish\components\CrelishDbStorage;
    use giantbits\crelish\components\CrelishDynamicModel;
    use giantbits\crelish\migrations\m260929_120000_create_menu_tables;
    use giantbits\crelish\models\CrelishTranslation;

    $root = sys_get_temp_dir() . '/crelish-writeback-test-' . getmypid();
    @mkdir($root . '/workspace/elements', 0777, true);
    @mkdir($root . '/runtime', 0777, true);
    file_put_contents($root . '/workspace/elements/wbprobe.json', json_encode([
        'key' => 'wbprobe',
        'label' => 'Probe',
        'storage' => 'db',
        'fields' => [
            ['label' => 'Title', 'key' => 'systitle', 'type' => 'textInput', 'translatable' => true, 'rules' => [['safe']]],
            ['label' => 'Sort', 'key' => 'sort', 'type' => 'textInput', 'rules' => [['safe']]],
        ],
    ]));

    $app = shortLinkApp(['languages' => ['de', 'en']], [], ['basePath' => $root]);
    (new m260929_120000_create_menu_tables(['db' => $app->db, 'compact' => true]))->up();
    $app->db->createCommand()->createTable('wbprobe', [
        'uuid' => 'varchar(36) NOT NULL PRIMARY KEY',
        'systitle' => 'varchar(255) NULL',
        'sort' => 'integer NULL',
        'state' => 'integer NULL',
        'created' => 'integer NULL',
        'updated' => 'integer NULL',
    ])->execute();

    $uuid = '5b0c7c1e-1f4c-4a57-9d55-0f3f2b0c0a01';
    $app->db->createCommand()->insert('wbprobe', ['uuid' => $uuid, 'systitle' => 'Termine', 'sort' => 1, 'state' => 2])->execute();
    $app->db->createCommand()->insert('translation', [
        'uuid' => 'wb-en', 'source_model' => 'wbprobe', 'source_model_uuid' => $uuid,
        'language' => 'en', 'source_model_attribute' => 'systitle', 'translation' => 'Events',
    ])->execute();

    $column = static fn(): string => (string)Yii::$app->db->createCommand('SELECT systitle FROM wbprobe WHERE uuid = :u', [':u' => $uuid])->queryScalar();

    // An admin controller without the cookie/session setup of CrelishBaseController::init()
    $adminController = new class('content', $app) extends CrelishBaseController {
        public function init(): void
        {
        }
    };

    echo "Admin editor in English\n";
    Yii::$app->controller = $adminController;
    Yii::$app->language = 'en';
    $model = new CrelishDynamicModel(['ctype' => 'wbprobe', 'uuid' => $uuid]);
    check('editor shows the default column', 'Termine', $model->systitle);
    check('language tabs still get the translations', 'Events', $model->allTranslations['systitle']['en'] ?? null);

    echo "\nCrelishDbStorage::save() update without the translated field\n";
    (new CrelishDbStorage())->save('wbprobe', ['uuid' => $uuid, 'sort' => 4], false);
    check('default column kept', ['Termine', 4], [$column(), (int)Yii::$app->db->createCommand('SELECT sort FROM wbprobe WHERE uuid = :u', [':u' => $uuid])->queryScalar()]);

    echo "\nCrelishDbStorage::save() with a deliberate new value\n";
    (new CrelishDbStorage())->save('wbprobe', ['uuid' => $uuid, 'systitle' => 'Neue Termine'], false);
    check('new default value written', 'Neue Termine', $column());
    check('translation row untouched', 'Events', CrelishTranslation::findOne(['source_model_uuid' => $uuid, 'language' => 'en'])->translation);
    Yii::$app->db->createCommand()->update('wbprobe', ['systitle' => 'Termine'], ['uuid' => $uuid])->execute();

    echo "\nFrontend (no admin controller)\n";
    Yii::$app->controller = null;
    $model = new CrelishDynamicModel(['ctype' => 'wbprobe', 'uuid' => $uuid]);
    check('frontend still shows the translation', 'Events', $model->systitle);
    Yii::$app->language = 'de';
    $model = new CrelishDynamicModel(['ctype' => 'wbprobe', 'uuid' => $uuid]);
    check('frontend in German shows the column', 'Termine', $model->systitle);

    \yii\helpers\FileHelper::removeDirectory($root);

    shortLinkDone();
}
