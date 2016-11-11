<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishUser;
use giantbits\crelish\models\LoginForm;
use yii\filters\AccessControl;



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

    public function actionIndex() {
      return $this->render('index.twig');
    }

    public function actionClearcache() {
      \Yii::$app->cache->flush();
      return $this->redirect('/crelish/settings/index',302);
    }

    public function actionClearwebassets() {
      $filePath = \Yii::getAlias('@app/web/assets');
      $files = scandir($filePath);
      foreach ($files as $f) {
        if (is_dir($filePath . DIRECTORY_SEPARATOR . $f) && substr($f,0,1)!='.') {
            $this->deleteRecursive($filePath . DIRECTORY_SEPARATOR . $f);
        }
      }
      return $this->redirect('/crelish/settings/index',302);
    }

    private function deleteRecursive($folder) {
        $files = scandir($folder);
        foreach ($files as $file) {
            if (!in_array($file, array('.','..'))) {
                $path = $folder . DIRECTORY_SEPARATOR . $file;
                if (is_dir($path)) {
                    $this->deleteRecursive($path);
                } else {
                    unlink($path);
                }
            }
        }
        rmdir($folder);
    }

}
