<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 17:19
 */

namespace giantbits\crelish\controllers;

use yii\base\Controller;

class DefaultController extends Controller {

  public $layout = 'crelish';

  public function actionIndex() {
    return $this->render('index.twig');
  }
}
