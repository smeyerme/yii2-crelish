<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 17:19
 */

namespace giantbits\crelish\controllers;

use ColorThief\ColorThief;
use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishDynamicJsonModel;
use yii\base\Controller;
use yii\helpers\Html;
use yii\web\UploadedFile;
use yii\helpers\Json;
use yii\helpers\Url;
use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\filters\AccessControl;

class AssetController extends CrelishBaseController
{

    public $layout = 'crelish.twig';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['login'],
                        'roles' => ['?'],
                    ],
                    [
                        'allow' => true,
                        'actions' => [],
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }

    public function init()
    {
        parent::init();
    }

    public function actionIndex()
    {
        $filter = null;
        if (!empty($_GET['cr_content_filter'])) {
            $filter = ['freesearch' => $_GET['cr_content_filter']];
        }

        $modelProvider = new CrelishJsonDataProvider('asset', ['filter' => $filter], NULL);
        $checkCol = [
            [
                'class' => 'yii\grid\CheckboxColumn'
            ],
            [
                'label' => \Yii::t('crelish', 'Preview'),
                'format' => 'raw',
                'value' => function ($model) {
                    $preview = \Yii::t('crelish', 'n/a');

                    switch ($model['mime']){
                        case 'image/jpeg':
                        case 'image/gif':
                        case 'image/png':
                            $preview = Html::img($model['src'], ['style' => 'width: 80px; height: auto;']);
                    }

                    return $preview;
                }
            ]
        ];
        $columns = array_merge($checkCol, $modelProvider->columns);

        $rowOptions = function ($model, $key, $index, $grid) {
            return ['onclick' => 'location.href="update.html?ctype=asset&uuid=' . $model['uuid'] .'";'];
        };

        return $this->render('index.twig', [
            'dataProvider' => $modelProvider->raw(),
            'filterProvider' => $modelProvider->getFilters(),
            'columns' => $columns,
            'ctype' => $this->ctype,
            'rowOptions' => $rowOptions
        ]);
    }

    public function actionUpdate()
    {
        $uuid = !empty(\Yii::$app->getRequest()->getQueryParam('uuid')) ? \Yii::$app->getRequest()->getQueryParam('uuid') : null;
        $model = new CrelishDynamicJsonModel([], ['uuid' => $uuid, 'ctype' => 'asset']);

        // Save content if post request.
        if (!empty(\Yii::$app->request->post()) && !\Yii::$app->request->isAjax) {
            $oldData = [];
            // Load old data.
            if (!empty($model->uuid)) {
                $oldData = Json::decode(file_get_contents(\Yii::getAlias('@app/workspace/data/') . DIRECTORY_SEPARATOR . 'asset' . DIRECTORY_SEPARATOR . $model->uuid . '.json'));
            }
            $model->attributes = $_POST['CrelishDynamicJsonModel'] + $oldData;

            if ($model->validate()) {
                $model->save();
                \Yii::$app->session->setFlash('success', 'Asset saved successfully...');
                header("Location: " . Url::to(['asset/update', 'uuid' => $model->uuid]));
                exit(0);
            } else {
                //var_dump($model->errors);
                \Yii::$app->session->setFlash('error', 'Asset save failed...');
            }
        }

        $alerts = '';
        foreach (\Yii::$app->session->getAllFlashes() as $key => $message) {
            $alerts .= '<div class="c-alerts__alert c-alerts__alert--' . $key . '">' . $message . '</div>';
        }

        return $this->render('update.twig', [
            'model' => $model,
            'alerts' => $alerts
        ]);
    }

    public function actionUpload()
    {

        $file = UploadedFile::getInstanceByName('file');

        if ($file) {
            $destName = time() . '_' . $file->name;
            $targetFile = \Yii::getAlias('@webroot') . DIRECTORY_SEPARATOR . '_lib' . DIRECTORY_SEPARATOR . $destName;
            $file->saveAs($targetFile);

            //$filePath = \Yii::getAlias('@app/workspace/elements') . DIRECTORY_SEPARATOR . 'asset' . '.json';
            //$elementDefinition = Json::decode(file_get_contents($filePath), false);

            // Add core fields.
            //$elementDefinition->fields[] = Json::decode('{ "label": "UUID", "key": "uuid", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
            //$elementDefinition->fields[] = Json::decode('{ "label": "Path", "key": "path", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
            //$elementDefinition->fields[] = Json::decode('{ "label": "Slug", "key": "slug", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
            //$elementDefinition->fields[] = Json::decode('{ "label": "State", "key": "state", "type": "dropDownList", "visibleInGrid": true, "rules": [["required"], ["string", {"max": 128}]], "options": {"prompt":"Please set state"}, "items": {"0":"Offline", "1":"Draft", "2":"Online", "3":"Archived"}}', false);

            ///$fields = [];

            //foreach ($elementDefinition->fields as $field) {
            //  array_push($fields, $field->key);
            //}

            $model = new CrelishDynamicJsonModel([], ['ctype' => 'asset']);
            $model->identifier = 'asset';
            $model->systitle = $destName;
            $model->title = $destName;
            $model->src = \Yii::getAlias('@web') . '/' . '_lib' . '/' . $destName;
            $model->mime = $file->type;
            $model->size = $file->size;
            $model->state = 2;
            $model->save();

            try {
                $domColor = ColorThief::getColor($targetFile, 20);
                $palColor = ColorThief::getPalette(\Yii::getAlias('@webroot') . DIRECTORY_SEPARATOR . '_lib' . DIRECTORY_SEPARATOR . $destName);
                $model->colormain_rgb = Json::encode($domColor);
                $model->colormain_hex = '#' . sprintf('%02x', $domColor[0]) . sprintf('%02x', $domColor[1]) . sprintf('%02x', $domColor[2]);
                $model->save();
                $model->colorpalette = Json::encode($palColor);
                $model->save();
            } catch (Exception $e) {
                \Yii::$app->session->setFlash('secondary', 'Color theft could not be completed. (Image too large?)');
            }
        }
        return false;
    }

    public function actionDelete()
    {
        $id = !empty(\Yii::$app->getRequest()->getQueryParam('uuid')) ? \Yii::$app->getRequest()->getQueryParam('uuid') : null;
        $modelProvider = new CrelishJsonDataProvider('asset', [], $id);
        $model = $modelProvider->one();
        if (@unlink(\Yii::getAlias('@webroot') . $model['src']) || !file_exists(\Yii::getAlias('@webroot') . $model['src'])) {
            $modelProvider->delete();
            \Yii::$app->session->setFlash('success', 'Asset deleted successfully...');
            header("Location: " . Url::to(['asset/index']));
            exit(0);
        };

        \Yii::$app->session->setFlash('danger', 'Asset could not be deleted...');
        header("Location: " . Url::to(['asset/index', ['uuid' => $model['uuid']]]));
        exit(0);
    }
}
