<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishDataManager;
use giantbits\crelish\components\CrelishDynamicModel;
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
        'class' => AccessControl::class,
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

    if (extension_loaded('apc') && ini_get('apc.enabled')) {
      apc_clear_cache();
    }

    opcache_reset();

    \Yii::$app->session->setFlash('success', \Yii::t('app', 'Caches cleared successfully.'));
    return $this->redirect('/crelish/settings/index', 302);
  }

  public function actionRebuildcache()
  {
    \Yii::$app->cache->flush();

    // Fetch data types.
    $dataManager = new CrelishDataManager('elements', [
      'sort' => ['label' => SORT_ASC],
      'pageSize' => 99
    ]);

    $importItems = $dataManager->all()['models'];

    foreach ($importItems as $item) {
      // Build cache for each.
      $dataCache = new CrelishDataManager($item['key']);
      $tmp = $dataCache->rawAll();
    }

    \Yii::$app->session->setFlash('success', \Yii::t('app', 'Caches rebuild successfully.'));
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
    \Yii::$app->session->setFlash('success', \Yii::t('app', 'WebAssets deleted successfully.'));
    return $this->redirect('/crelish/settings/index', 302);
  }

  public function actionIntellicache()
  {
    //2e212e112e-2e12ea-vhrto4

    //Rebuild caches
    if (\Yii::$app->request->get('auth') != "2e212e112e-2e12ea-vhrto4") {
      die("NOT ALLOWED");
    }

    $files = FileHelper::findFiles(\Yii::getAlias('@app/workspace/data'));

    foreach ($files as $file) {
      $contents = file_get_contents($file);
      if (!strpos($contents, \Yii::$app->request->get('uuid'))) continue;

      $json = Json::decode($contents);
      if ($json['uuid'] == \Yii::$app->request->get('uuid')) continue;

      $ctypeArr = explode(DIRECTORY_SEPARATOR, $file);
      $ctype = $ctypeArr[count($ctypeArr) - 2];

      $item = new CrelishDynamicModel(['ctype' => $ctype, 'uuid' => $json['uuid']]);
      $item->save();

      var_dump($json);
    }
    die();
  }

  /**
   * Override the setupHeaderBar method for settings-specific components
   */
  protected function setupHeaderBar()
  {
    // Default left components for all actions
    $this->view->params['headerBarLeft'] = ['toggle-sidebar'];
    
    // Default right components (empty by default)
    $this->view->params['headerBarRight'] = [];
    
    // Set specific components based on action
    $action = $this->action ? $this->action->id : null;
    
    switch ($action) {
      case 'index':
        // For settings index, add a title
        $this->view->params['headerBarLeft'][] = ['title', 'Settings'];
        break;
        
      default:
        // For other actions, just keep the defaults
        break;
    }
  }
}
