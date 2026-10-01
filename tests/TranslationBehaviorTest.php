<?php

/**
 * CrelishTranslationBehavior: programmatic translations (set, load, clear)
 * and the unchanged form POST path.
 *
 * Run with:  php tests/TranslationBehaviorTest.php
 */

declare(strict_types=1);

require __DIR__ . '/menu/bootstrap.php';

use giantbits\crelish\components\CrelishTranslationBehavior;
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

echo "\nRegional locale falls back to the language code\n";
$loc = makeItem($menu, ['label' => 'Ort']);
$loc->setTranslations(['en' => ['label' => 'Place']]);
$loc->save(false);
Yii::$app->language = 'en-US';
check('en-US uses the en row', 'Place', MenuItem::findOne($loc->uuid)->label);
$loc = MenuItem::findOne($loc->uuid);
$loc->setTranslations(['en-US' => ['label' => 'Spot']]);
$loc->save(false);
check('exact en-US row wins over en', 'Spot', MenuItem::findOne($loc->uuid)->label);
Yii::$app->language = 'en';
check('plain en still reads the en row', 'Place', MenuItem::findOne($loc->uuid)->label);
Yii::$app->language = 'de-CH';
$plain = makeItem($menu, ['label' => 'Kontakt']);
check('de-CH without rows keeps the column value', 'Kontakt', MenuItem::findOne($plain->uuid)->label);
Yii::$app->language = 'de';

echo "\nPOST path skips array values\n";
Yii::$app->request->setBodyParams(['CrelishDynamicModel' => ['i18n' => ['fr' => ['label' => []]]]]);
$threw = false;
try {
  MenuItem::findOne($loc->uuid)->save(false);
} catch (\Throwable $e) {
  $threw = true;
}
check('array POST value does not throw', false, $threw);
check('array POST value stores no row', 0, (int)CrelishTranslation::find()->where(['source_model_uuid' => $loc->uuid, 'language' => 'fr'])->count());
Yii::$app->request->setBodyParams([]);

echo "\nTranslated values are never written into the default column\n";
$wb = makeItem($menu, ['label' => 'Termine', 'sort' => 1]);
$wb->setTranslations(['en' => ['label' => 'Events']]);
$wb->save(false);
Yii::$app->language = 'en';
$found = MenuItem::findOne($wb->uuid);
check('display still translated', 'Events', $found->label);
$found->sort = 5;
$found->save(false);
Yii::$app->language = 'de';
$raw = Yii::$app->db->createCommand('SELECT label, sort FROM menu_item WHERE uuid = :u', [':u' => $wb->uuid])->queryOne();
check('unrelated save keeps the default column', ['Termine', 5], [$raw['label'], (int)$raw['sort']]);
check('translation row untouched', 'Events', CrelishTranslation::findOne(['source_model_uuid' => $wb->uuid, 'language' => 'en'])->translation);

Yii::$app->language = 'en-US';
$found = MenuItem::findOne($wb->uuid);
check('en-US displays the en row', 'Events', $found->label);
$found->sort = 6;
$found->save(false);
Yii::$app->language = 'de';
check('fallback path keeps the default column', 'Termine', Yii::$app->db->createCommand('SELECT label FROM menu_item WHERE uuid = :u', [':u' => $wb->uuid])->queryScalar());

Yii::$app->language = 'en';
$found = MenuItem::findOne($wb->uuid);
$found->label = 'Neu';
$found->save(false);
Yii::$app->language = 'de';
check('deliberate change is still written', 'Neu', Yii::$app->db->createCommand('SELECT label FROM menu_item WHERE uuid = :u', [':u' => $wb->uuid])->queryScalar());

Yii::$app->language = 'en';
$found = MenuItem::findOne($wb->uuid);
$found->sort = 7;
$found->save(false);
$found->label = 'Events';
$found->save(false);
Yii::$app->language = 'de';
check('after the first update the swap is forgotten; an explicit later set is written', 'Events', Yii::$app->db->createCommand('SELECT label FROM menu_item WHERE uuid = :u', [':u' => $wb->uuid])->queryScalar());
Yii::$app->db->createCommand()->update('menu_item', ['label' => 'Termine'], ['uuid' => $wb->uuid])->execute();

echo "\nwithoutTranslations()\n";
Yii::$app->language = 'en';
check('inside: raw column value', 'Termine', CrelishTranslationBehavior::withoutTranslations(fn() => MenuItem::findOne($wb->uuid)->label));
check('outside: translated again', 'Events', MenuItem::findOne($wb->uuid)->label);
check('nested: inner and outer raw', ['Termine', 'Termine'], CrelishTranslationBehavior::withoutTranslations(function () use ($wb) {
  $inner = CrelishTranslationBehavior::withoutTranslations(fn() => MenuItem::findOne($wb->uuid)->label);
  return [$inner, MenuItem::findOne($wb->uuid)->label];
}));
$caught = false;
try {
  CrelishTranslationBehavior::withoutTranslations(function () { throw new RuntimeException('boom'); });
} catch (RuntimeException $e) {
  $caught = true;
}
check('exception propagates', true, $caught);
check('switch restored after exception', 'Events', MenuItem::findOne($wb->uuid)->label);
Yii::$app->language = 'de';

echo "\nRows for the default content language are never swapped in\n";
$stale = makeItem($menu, ['label' => 'Neu']);
Yii::$app->db->createCommand()->insert('translation', ['uuid' => 'stale-de', 'source_model' => 'menu_item', 'source_model_uuid' => $stale->uuid, 'language' => 'de', 'source_model_attribute' => 'label', 'translation' => 'Alt'])->execute();
Yii::$app->db->createCommand()->insert('translation', ['uuid' => 'stale-en', 'source_model' => 'menu_item', 'source_model_uuid' => $stale->uuid, 'language' => 'en', 'source_model_attribute' => 'label', 'translation' => 'New'])->execute();
$languagesBefore = Yii::$app->params['crelish']['languages'];
Yii::$app->params['crelish']['languages'] = ['de', 'en'];
Yii::$app->language = 'de';
check('de shows the column, not the stale de row', 'Neu', MenuItem::findOne($stale->uuid)->label);
Yii::$app->language = 'de-CH';
check('de-CH shows the column too', 'Neu', MenuItem::findOne($stale->uuid)->label);
Yii::$app->language = 'en';
check('en still swaps its translation', 'New', MenuItem::findOne($stale->uuid)->label);
Yii::$app->params['crelish']['languages'] = [];
Yii::$app->language = 'de';
check('no language list: unchanged, the de row is swapped in', 'Alt', MenuItem::findOne($stale->uuid)->label);
Yii::$app->params['crelish']['languages'] = $languagesBefore;
Yii::$app->language = 'de';

echo "\nA full locale as default content language is compared by its language code\n";
Yii::$app->params['crelish']['languages'] = ['de-CH', 'en'];
Yii::$app->language = 'de';
check('de-CH default + app de: column, not the stale de row', 'Neu', MenuItem::findOne($stale->uuid)->label);
Yii::$app->language = 'en';
check('de-CH default + app en: translation swapped', 'New', MenuItem::findOne($stale->uuid)->label);
Yii::$app->params['crelish']['languages'] = $languagesBefore;
Yii::$app->language = 'de';

echo "\nLocale fallback per field\n";
// menu_item has no title column; target_url stands in for a second translated field
$pf = makeItem($menu, ['label' => 'Label DE', 'target_url' => '/de/titel']);
foreach ([['en', 'label', 'Label EN'], ['en', 'target_url', 'Title EN'], ['en-US', 'label', 'Label US']] as $i => [$lang, $attr, $value]) {
  Yii::$app->db->createCommand()->insert('translation', ['uuid' => 'pf-' . $i, 'source_model' => 'menu_item', 'source_model_uuid' => $pf->uuid, 'language' => $lang, 'source_model_attribute' => $attr, 'translation' => $value])->execute();
}
Yii::$app->language = 'en-US';
$found = MenuItem::findOne($pf->uuid);
check('en-US: exact row wins for label', 'Label US', $found->label);
check('en-US: en row fills the field without an en-US row', 'Title EN', $found->target_url);
Yii::$app->language = 'en';
$found = MenuItem::findOne($pf->uuid);
check('en: only en rows', ['Label EN', 'Title EN'], [$found->label, $found->target_url]);
Yii::$app->language = 'en-US';
$found = MenuItem::findOne($pf->uuid);
$found->sort = 9;
$found->save(false);
Yii::$app->language = 'de';
$raw = Yii::$app->db->createCommand('SELECT label, target_url, sort FROM menu_item WHERE uuid = :u', [':u' => $pf->uuid])->queryOne();
check('en-US mixed rows: unrelated save keeps the default columns', ['Label DE', '/de/titel', 9], [$raw['label'], $raw['target_url'], (int)$raw['sort']]);
check('en-US mixed rows: translation rows untouched', 3, (int)CrelishTranslation::find()->where(['source_model_uuid' => $pf->uuid])->count());

shortLinkDone();
