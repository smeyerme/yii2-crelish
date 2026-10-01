<?php

/**
 * The JSON editor plugins (jsonEditor, jsonEditorNew) follow the admin form's
 * language logic: the main field edits the default content language, each other
 * configured language gets an i18n[<lang>] field, and data-language matches, so the
 * language selector shows the right one. Rendered through the controller's
 * renderField() like the admin form does.
 *
 * Run with:  php tests/JsonEditorLanguageTest.php
 */

declare(strict_types=1);

require __DIR__ . '/menu/bootstrap.php';

use giantbits\crelish\components\CrelishBaseController;

class JsonEditorTestModel extends \yii\base\Model
{
    public $i18n = [];
    public $allTranslations = null;
    public $content;
    public $config;
}

/** The plugins register jQuery-dependent files; the test app has no bower jQuery to publish */
function jeApp(array $languages): void
{
    menuApp(['languages' => $languages]);
    // menuApp() merges recursively into [de, en]; set the list exactly
    Yii::$app->params['crelish']['languages'] = $languages;
    Yii::$app->assetManager->bundles = [\yii\web\JqueryAsset::class => false];
}

function jeController(JsonEditorTestModel $model): Closure
{
    $controller = new class('content', Yii::$app) extends CrelishBaseController {
        public function init(): void
        {
        }
    };
    $controller->model = $model;

    return static function (object $field) use ($controller): string {
        $reflection = new ReflectionMethod(CrelishBaseController::class, 'renderField');

        return (string)$reflection->invoke($controller, $field, null);
    };
}

function jeField(string $type, string $key, bool $translatable): object
{
    $field = (object)['key' => $key, 'label' => ucfirst($key), 'type' => $type, 'schema' => (object)['type' => 'array']];
    if ($translatable) {
        $field->translatable = true;
    }

    return $field;
}

/** name => [data-language of the hidden input or null, value] */
function jeInputs(string $html): array
{
    preg_match_all('/<input type="hidden"[^>]*>/', $html, $m);
    $inputs = [];
    foreach ($m[0] as $tag) {
        preg_match('/name="([^"]+)"/', $tag, $name);
        preg_match('/value="([^"]*)"/', $tag, $value);
        preg_match('/data-language="([^"]+)"/', $tag, $lang);
        $inputs[html_entity_decode($name[1])] = [$lang[1] ?? null, html_entity_decode($value[1] ?? '')];
    }

    return $inputs;
}

function jeIds(string $html): array
{
    preg_match_all('/<div id="(json-editor[^"]+)"/', $html, $m);

    return $m[1];
}

foreach (['jsonEditor' => 'json-editor-', 'jsonEditorNew' => 'json-editor-new-'] as $type => $idPrefix) {
    jeApp(['de', 'en']);
    Yii::$app->language = 'en';

    echo "$type: admin UI en, content languages [de, en]\n";
    $model = new JsonEditorTestModel();
    $model->content = '[{"t":"Deutsch"}]';
    $model->allTranslations = ['content' => ['en' => '[{"t":"English"}]']];
    $html = jeController($model)(jeField($type, 'content', true));
    $inputs = jeInputs($html);
    check('main field posts the default column', ['de', '[{"t":"Deutsch"}]'], $inputs['CrelishDynamicModel[content]'] ?? null);
    check('en field posts i18n[en] with its stored translation', ['en', '[{"t":"English"}]'], $inputs['CrelishDynamicModel[i18n][en][content]'] ?? null);
    check('exactly two fields: main and en', 2, count($inputs));
    check('form groups carry data-language de and en', 2, preg_match_all('/<div class="form-group[^"]*"[^>]*data-language="(de|en)"/', $html));
    check('en form group is marked as translation', 1, preg_match('/<div class="form-group[^"]*lang-ver[^"]*"[^>]*data-language="en"/', $html));
    check('editor containers have distinct ids', [$idPrefix . 'content', $idPrefix . 'content-en'], jeIds($html));
    check('containers carry their language', 2, preg_match_all('/<div id="json-editor[^"]+"[^>]*data-language="(de|en)"/', $html));

    echo "\n$type: no stored translation falls back to the column (like the core fields)\n";
    $model = new JsonEditorTestModel();
    $model->content = '[{"t":"Deutsch"}]';
    $inputs = jeInputs(jeController($model)(jeField($type, 'content', true)));
    check('en field shows the column value', ['en', '[{"t":"Deutsch"}]'], $inputs['CrelishDynamicModel[i18n][en][content]'] ?? null);

    echo "\n$type: a re-rendered POST value wins\n";
    $model = new JsonEditorTestModel();
    $model->content = '[{"t":"Deutsch"}]';
    $model->allTranslations = ['content' => ['en' => '[{"t":"English"}]']];
    $model->i18n = ['en' => ['content' => '[{"t":"Posted"}]']];
    $inputs = jeInputs(jeController($model)(jeField($type, 'content', true)));
    check('en field shows the posted value', ['en', '[{"t":"Posted"}]'], $inputs['CrelishDynamicModel[i18n][en][content]'] ?? null);
    check('main field still the column', ['de', '[{"t":"Deutsch"}]'], $inputs['CrelishDynamicModel[content]'] ?? null);

    echo "\n$type: non-translatable field is untouched\n";
    $model = new JsonEditorTestModel();
    $model->config = '{"a":1}';
    $html = jeController($model)(jeField($type, 'config', false));
    check('plain name, no language', ['CrelishDynamicModel[config]' => [null, '{"a":1}']], jeInputs($html));
    check('no data-language anywhere', 0, substr_count($html, 'data-language'));

    echo "\n$type: single-language install\n";
    jeApp(['de']);
    Yii::$app->language = 'de';
    $model = new JsonEditorTestModel();
    $model->config = '{"a":1}';
    $html = jeController($model)(jeField($type, 'config', false));
    check('plain name, no language', ['CrelishDynamicModel[config]' => [null, '{"a":1}']], jeInputs($html));
    check('no data-language anywhere', 0, substr_count($html, 'data-language'));
    check('editor id unchanged', [$idPrefix . 'config'], jeIds($html));
    $model->content = '[{"t":"Deutsch"}]';
    check('translatable field: the form renders no language fields (as before)', '', jeController($model)(jeField($type, 'content', true)));
    echo "\n";
}

Yii::$app->language = 'de';
shortLinkDone();
