<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 17:19
 */

namespace giantbits\crelish\controllers;

use yii\base\Controller;
use yii\filters\AccessControl;

class DashboardController extends Controller {

    public $layout = 'crelish.twig';

    public function behaviors() {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['create', 'index', 'delete'],
                'rules' => [
                    [
                        'allow' => TRUE,
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }

    public function init() {
        parent::init(); // TODO: Change the autogenerated stub
    }

    public function actionIndex() {
        return $this->render('index.twig');
    }
}
