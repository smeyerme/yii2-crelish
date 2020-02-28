<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishDynamicJsonModel;
use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\filters\AccessControl;
use yii\helpers\FileHelper;
use yii\helpers\Json;


class SettingsController extends CrelishBaseController
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
    return $this->render('index.twig');
  }

  public function actionClearcache()
  {
    \Yii::$app->cache->flush();

    if(extension_loaded('apc') && ini_get('apc.enabled'))
    {
      apc_clear_cache();
    }

    opcache_reset();

    return $this->redirect('/crelish/settings/index', 302);
  }

  public function actionRebuildcache() {
      \Yii::$app->cache->flush();

      // Fetch data types.
      $elements = new CrelishJsonDataProvider('elements', [
          'key' => 'key',
          'sort' => ['by' => ['label', 'asc']],
          'limit' => 99
      ]);

      $importItems = $elements->all()['models'];

      foreach ($importItems as $item) {
          // Build cache for each.
          $dataCache = new CrelishJsonDataProvider($item['key']);
          $tmp = $dataCache->rawAll();
      }

      \Yii::$app->session->setFlash('success', 'Caches rebuild successfully...');
      return $this->redirect('/crelish/settings/index', 302);
  }

  public function actionClearwebassets()
  {
    $filePath = \Yii::getAlias('@app/web/assets');
    $files = scandir($filePath);
    foreach ($files as $f) {
      if (is_dir($filePath . DIRECTORY_SEPARATOR . $f) && substr($f, 0, 1) != '.') {
        FileHelper::removeDirectory($filePath . DIRECTORY_SEPARATOR . $f);
      }
    }
    return $this->redirect('/crelish/settings/index', 302);
  }

  public function actionIntellicache() {
      //2e212e112e-2e12ea-vhrto4

      //Rebuild caches
      if(\Yii::$app->request->get('auth') != "2e212e112e-2e12ea-vhrto4") {
          die("NOT ALLOWED");
      }

      $files = FileHelper::findFiles(\Yii::getAlias('@app/workspace/data'));

      foreach($files as $file) {
          $contents = file_get_contents($file);
          if (!strpos($contents, \Yii::$app->request->get('uuid'))) continue;

          $json = Json::decode($contents);
          if($json['uuid'] == \Yii::$app->request->get('uuid')) continue;

          $ctypeArr = explode(DIRECTORY_SEPARATOR, $file);
          $ctype = $ctypeArr[count($ctypeArr)-2];

          $item = new CrelishDynamicJsonModel([],['ctype'=>$ctype, 'uuid'=>$json['uuid']]);
          $item->save();

          var_dump($json);
      }
      die();
  }
}
