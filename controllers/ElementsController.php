<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishDynamicJsonModel;
use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\filters\AccessControl;
use yii\helpers\FileHelper;
use yii\helpers\Json;


class ElementsController extends CrelishBaseController
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

  public function actionIndex() {
      $files = FileHelper::findFiles(\Yii::getAlias('@app/workspace/elements'));
      $data = [];
      foreach ($files as $f) {
          $tmp = json_decode(file_get_contents($f));
          $data[$tmp->label] = basename($f);
      }
      ksort($data);

      return $this->render('index.twig',['data'=>$data]);
  }

  public function actionEdit() {
      if (!preg_match('/^[a-zA-Z0-9\-_]+\.json$/',$_GET['element'])) {
          echo "nice try...";
          die();
      }
      $elementFileName = $_GET['element'];
      $data = json_decode(file_get_contents(\Yii::getAlias('@app/workspace/elements/'.$elementFileName)));

      foreach ($data->fields as $key => $val) {
          $data->fields[$key]->fielddata = json_encode($val);
      }

      return $this->render('edit.twig',['element'=>$data]);
  }

}
