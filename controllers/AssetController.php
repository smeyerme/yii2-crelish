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
use giantbits\crelish\components\CrelishDynamicModel;
use League\Glide\ServerFactory;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\UploadedFile;
use yii\helpers\Url;
use giantbits\crelish\components\CrelishDataProvider;
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
            'actions' => ['login', 'glide'],
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
    $this->enableCsrfValidation = false;
    parent::init();
  }

  public function actions()
  {
    return [
      //'glide' => 'trntv\glide\actions\GlideAction'
    ];
  }

  public function actionGlide()
  {
    $path = \Yii::$app->request->get('path', null);
    $params = \Yii::$app->request->getQueryParams();

    unset($params['path']);

    // Todo: Add image manipulation support.

    $server = ServerFactory::create([
      'source' => \Yii::getAlias('@app/web/uploads'),
      'cache' => \Yii::getAlias('@runtime/glide'),
      'presets' => \Yii::$app->params['crelish']['glide_presets']
    ]);

    $server->outputImage($path, $params);
  }

  public function actionIndex()
  {
    $this->enableCsrfValidation = false;
    $filter = null;
    if (!empty($_GET['cr_content_filter'])) {
      $filter = ['freesearch' => $_GET['cr_content_filter']];
    }

    $modelProvider = new CrelishDataProvider('asset', ['filter' => $filter], NULL);
    $checkCol = [
      [
        'class' => 'yii\grid\CheckboxColumn'
      ],
      [
        'label' => \Yii::t('crelish', 'Preview'),
        'format' => 'raw',
        'value' => function ($model) {
          $preview = \Yii::t('crelish', 'n/a');

          switch ($model['mime']) {
            case 'image/jpeg':
            case 'image/gif':
            case 'image/png':
              $preview = Html::img('/crelish/asset/glide.html?path=' . $model['fileName'] . '&w=160&f=fit', ['style' => 'width: 80px; height: auto;']);
          }

          return $preview;
        }
      ]
    ];
    $columns = array_merge($checkCol, $modelProvider->columns);

    $rowOptions = function ($model, $key, $index, $grid) {
      return ['onclick' => 'location.href="update.html?ctype=asset&uuid=' . $model['uuid'] . '";'];
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
    $model = new CrelishDynamicModel([], ['uuid' => $uuid, 'ctype' => 'asset']);


    // Save content if post request.
    if (!empty(\Yii::$app->request->post()) && !\Yii::$app->request->isAjax) {
      $model->attributes = $_POST['CrelishDynamicModel'];

      if ($model->validate()) {
        $model->save();

        if (!empty($_POST['save_n_return']) && $_POST['save_n_return'] == "1") {
          header('Location: ' . Url::to([
              'asset/index'
            ]));

          exit(0);
        }

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
      $targetFile = \Yii::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $destName;
      $file->saveAs($targetFile);

      $model = new CrelishDynamicModel([], ['ctype' => 'asset']);
      $model->systitle = $destName;
      $model->title = $destName;
      //$model->src = \Yii::getAlias('@webroot') . '/' . 'uploads' . '/' . $destName;
      $model->src = $destName;
      $model->fileName = $destName;
      $model->pathName = '/' . 'uploads' . '/';
      $model->mime = mime_content_type($targetFile);
      $model->size = $file->size;
      $model->state = 2;

      try {
        //$domColor = ColorThief::getColor($targetFile, 20);
        //$palColor = ColorThief::getPalette(\Yii::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $destName);

        //$model->colormain_rgb = Json::encode($domColor);
        //$model->colormain_hex = '#' . sprintf('%02x', $domColor[0]) . sprintf('%02x', $domColor[1]) . sprintf('%02x', $domColor[2]);
        //$model->colorpalette = Json::encode($palColor);

      } catch (Exception $e) {
        \Yii::$app->session->setFlash('secondary', 'Color theft could not be completed. (Image too large?)');
      }

      $model->save();
    }

    return false;
  }

  public function actionDelete()
  {
    $uuid = !empty(\Yii::$app->getRequest()->getQueryParam('uuid')) ? \Yii::$app->getRequest()->getQueryParam('uuid') : null;
    $modelProvider = new CrelishDynamicModel([], ['ctype' => 'asset', 'uuid' => $uuid]);
    if (@unlink(\Yii::getAlias('@webroot') . $modelProvider->src) || !file_exists(\Yii::getAlias('@webroot') . $modelProvider->src)) {
      $modelProvider->delete();
      \Yii::$app->session->setFlash('success', 'Asset deleted successfully...');
      header("Location: " . Url::to(['asset/index']));
      exit(0);
    };

    \Yii::$app->session->setFlash('danger', 'Asset could not be deleted...');
    header("Location: " . Url::to(['asset/index', ['uuid' => $modelProvider->uuid]]));
    exit(0);
  }
}
