<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 17:19
 */

namespace giantbits\crelish\controllers;
use giantbits\crelish\components\CrelishFrontendController;
use Spatie\SchemaOrg\VacationRental;
use Yii;

class FrontendController extends CrelishFrontendController {

  public function init()
  {
    parent::init();

  }

  public function afterAction($action, $result)
  {

    if(!Yii::$app->request->isAjax && Yii::$app->has('analytics')) {
      Yii::$app->analytics->trackPageView();
    }

    return parent::afterAction($action, $result);
  }

}
