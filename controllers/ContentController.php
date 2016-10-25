<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishJsonDataProvider;
use giantbits\crelish\components\CrelishDynamicJsonModel;
use giantbits\crelish\components\CrelishBaseController;
use yii\helpers\Json;
use yii\helpers\Url;

class ContentController extends CrelishBaseController
{
    public $layout = 'crelish.twig';

    public function init()
    {
      parent::init();

      $this->ctype = (!empty(\Yii::$app->getRequest()->getQueryParam('ctype'))) ? \Yii::$app->getRequest()->getQueryParam('ctype') : 'page';
      $this->uuid = (!empty(\Yii::$app->getRequest()->getQueryParam('uuid'))) ? \Yii::$app->getRequest()->getQueryParam('uuid') : null;
    }

    public function actionIndex()
    {
      $modelProvider = new CrelishJsonDataProvider($this->ctype, [], null);

      return $this->render('content.twig', [
        'dataProvider' => $modelProvider->raw(),
        'columns' => $modelProvider->columns,
        'ctype' => $this->ctype,
      ]);
    }

    public function actionCreate()
    {
      $content = $this->buildForm();

      return $this->render('create.twig', [
        'content' => $content,
        'ctype' => $this->ctype,
        'uuid' => $this->uuid,
      ]);
    }

    public function actionUpdate()
    {
      $content = $this->buildForm();

      return $this->render('create.twig', [
        'content' => $content,
        'ctype' => $this->ctype,
        'uuid' => $this->uuid,
      ]);
    }

    public function actionDelete()
    {
      $ctype = \Yii::$app->request->post('ctype');
      $uuid = \Yii::$app->request->post('uuid');

      // Build form for type.
      $filePath = \Yii::getAlias('@app/workspace/data/'.$ctype).DIRECTORY_SEPARATOR.$uuid.'.json';

      $result = unlink($filePath); // or you can set for test -> false;
      $return_json = ['status' => 'error'];
      if ($result == true) {
          $return_json = ['status' => 'success', 'message' => 'successfully deleted', 'redirect' => Url::toRoute(['content/index'])];
      }
      \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

      return $return_json;
    }
}
