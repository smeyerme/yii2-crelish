<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishDynamicJsonModel;
use giantbits\crelish\components\CrelishJsonDataProvider;
use giantbits\crelish\components\CrelishBaseController;
use yii\helpers\Url;
use yii\filters\AccessControl;

class ContentController extends CrelishBaseController {
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

    /**
     * [init description]
     * @return [type] [description]
     */
    public function init() {

        $this->ctype = (!empty(\Yii::$app->getRequest()
            ->getQueryParam('ctype'))) ? \Yii::$app->getRequest()
            ->getQueryParam('ctype') : 'page';
        $this->uuid = (!empty(\Yii::$app->getRequest()
            ->getQueryParam('uuid'))) ? \Yii::$app->getRequest()
            ->getQueryParam('uuid') : NULL;


        return parent::init();
    }

    /**
     * [actionIndex description]
     * @return [type] [description]
     */
    public function actionIndex() {
        $filter = null;
        if (!empty($_GET['cr_content_filter'])) {
            $filter = ['freesearch' => $_GET['cr_content_filter']];
        }

        $modelProvider = new CrelishJsonDataProvider($this->ctype, ['filter' => $filter], NULL);
        $checkCol = [
          [
            'class' => 'yii\grid\CheckboxColumn'
          ]
        ];

        $columns = array_merge($checkCol, $modelProvider->columns);

        $rowOptions = function ($model, $key, $index, $grid) {
            return ['onclick' => 'location.href="update.html?ctype=' . $model['ctype'] . '&uuid=' . $model['uuid'] .'";'];
        };

        return $this->render('content.twig', [
            'dataProvider' => $modelProvider->raw(),
            'filterProvider' => $modelProvider->getFilters(),
            'columns' => $columns,
            'ctype' => $this->ctype,
            'rowOptions' => $rowOptions
        ]);
    }

    /**
     * [actionCreate description]
     * @return [type] [description]
     */
    public function actionCreate() {
        $content = $this->buildForm();

        return $this->render('create.twig', [
            'content' => $content,
            'ctype' => $this->ctype,
            'uuid' => $this->uuid,
        ]);
    }

    /**
     * [actionUpdate description]
     * @return [type] [description]
     */
    public function actionUpdate() {
        $content = $this->buildForm();

        return $this->render('create.twig', [
            'content' => $content,
            'ctype' => $this->ctype,
            'uuid' => $this->uuid,
        ]);
    }

    /**
     * [actionDelete description]
     * @return [type] [description]
     */
    public function actionDelete() {
        $ctype = \Yii::$app->request->post('ctype');
        $uuid = \Yii::$app->request->post('uuid');

        // Build form for type.
        $filePath = \Yii::getAlias('@app/workspace/data/' . $ctype) . DIRECTORY_SEPARATOR . $uuid . '.json';

        $result = unlink($filePath); // or you can set for test -> false;
        $return_json = ['status' => 'error'];
        if ($result == TRUE) {
            $return_json = [
                'status' => 'success',
                'message' => 'successfully deleted',
                'redirect' => Url::toRoute(['content/index'])
            ];
        }
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        return $return_json;
    }
}
