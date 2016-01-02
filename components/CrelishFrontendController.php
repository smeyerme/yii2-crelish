<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 17:17
 */

namespace app\components;

use yii;
use yii\base\Controller;

class CrelishFrontendController extends CrelishBaseController {

  public function actionError() {
    $this->title = 'Error';
    Yii::$app->name = $this->title;

    $exception = Yii::$app->errorHandler->exception;

    if ($exception !== null) {
      return $this->render('error.mustache', ['message' => $exception->getMessage()]);
    }
  }

  public function actionIndex() {

  }

}
