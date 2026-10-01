<?php

/**
 * CrelishTranslationBehavior: programmatic translations (set, load, clear)
 * and the unchanged form POST path.
 *
 * Run with:  php tests/TranslationBehaviorTest.php
 */

declare(strict_types=1);

require __DIR__ . '/menu/bootstrap.php';

use giantbits\crelish\models\CrelishTranslation;
use giantbits\crelish\models\MenuItem;

$app = menuApp(['languages' => ['de', 'en', 'fr']]);
$menu = makeMenu('main');
$item = makeItem($menu, ['label' => 'Termine']);

echo "setTranslations()\n";
$item->setTranslations(['en' => ['label' => 'Events']]);
$item->save(false);
check('row written', 1, (int)CrelishTranslation::find()->where(['source_model' => 'menu_item', 'source_model_uuid' => $item->uuid, 'language' => 'en'])->count());

Yii::$app->language = 'en';
check('loaded in English', 'Events', MenuItem::findOne($item->uuid)->label);
Yii::$app->language = 'de';
check('default language keeps the column value', 'Termine', MenuItem::findOne($item->uuid)->label);

$reloaded = MenuItem::findOne($item->uuid);
$reloaded->setTranslations(['en' => ['label' => 'Dates']]);
$reloaded->save(false);
Yii::$app->language = 'en';
check('existing row updated, not duplicated', ['Dates', 1], [MenuItem::findOne($item->uuid)->label, (int)CrelishTranslation::find()->where(['source_model_uuid' => $item->uuid])->count()]);
Yii::$app->language = 'de';

$reloaded = MenuItem::findOne($item->uuid);
$reloaded->setTranslations(['en' => ['label' => '']]);
$reloaded->save(false);
check('empty value deletes the row', 0, (int)CrelishTranslation::find()->where(['source_model_uuid' => $item->uuid, 'language' => 'en'])->count());

// Same instance, second save: pending translations were cleared by the first save
$same = MenuItem::findOne($item->uuid);
$same->setTranslations(['en' => ['label' => 'Events']]);
$same->save(false);
Yii::$app->db->createCommand()->delete('translation', ['source_model_uuid' => $item->uuid, 'language' => 'en'])->execute();
Yii::$app->db->createCommand()->insert('translation', ['uuid' => 'direct-row', 'source_model' => 'menu_item', 'source_model_uuid' => $item->uuid, 'language' => 'en', 'source_model_attribute' => 'label', 'translation' => 'Direct'])->execute();
$same->save(false);
check('pending translations are consumed once', 'Direct', CrelishTranslation::findOne(['source_model_uuid' => $item->uuid, 'language' => 'en'])->translation);
Yii::$app->db->createCommand()->delete('translation', ['source_model_uuid' => $item->uuid])->execute();

echo "\nForm POST path unchanged\n";
Yii::$app->request->setBodyParams(['CrelishDynamicModel' => ['i18n' => ['fr' => ['label' => 'Agenda'], 'en' => ['label' => '']]]]);
$posted = MenuItem::findOne($item->uuid);
$posted->save(false);
check('POST value saved', 1, (int)CrelishTranslation::find()->where(['source_model_uuid' => $item->uuid, 'language' => 'fr'])->count());
check('POST empty value is skipped, not stored', 0, (int)CrelishTranslation::find()->where(['source_model_uuid' => $item->uuid, 'language' => 'en'])->count());
Yii::$app->request->setBodyParams([]);

echo "\nZero is a value, not empty\n";
$zero = MenuItem::findOne($item->uuid);
$zero->setTranslations(['en' => ['label' => '0']]);
$zero->save(false);
check('"0" is stored', '0', CrelishTranslation::findOne(['source_model_uuid' => $item->uuid, 'language' => 'en', 'source_model_attribute' => 'label'])->translation);
$zero = MenuItem::findOne($item->uuid);
$zero->setTranslations(['en' => ['label' => '']]);
$zero->save(false);
check('empty string still deletes it', 0, (int)CrelishTranslation::find()->where(['source_model_uuid' => $item->uuid, 'language' => 'en'])->count());
Yii::$app->request->setBodyParams(['CrelishDynamicModel' => ['i18n' => ['en' => ['label' => '0']]]]);
MenuItem::findOne($item->uuid)->save(false);
check('POST "0" is stored', '0', CrelishTranslation::findOne(['source_model_uuid' => $item->uuid, 'language' => 'en', 'source_model_attribute' => 'label'])->translation);
Yii::$app->request->setBodyParams([]);

shortLinkDone();
