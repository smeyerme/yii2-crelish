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

        /*$this->ctype = (!empty(\Yii::$app->getRequest()
            ->getQueryParam('ctype'))) ? \Yii::$app->getRequest()
            ->getQueryParam('ctype') : 'page';*/
        $this->uuid = (!empty(\Yii::$app->getRequest()
            ->getQueryParam('uuid'))) ? \Yii::$app->getRequest()
            ->getQueryParam('uuid') : NULL;


        if(key_exists('cr_content_filter', $_GET)) {
            \Yii::$app->session->set('cr_content_filter',$_GET['cr_content_filter']);
        } else {
            if(!empty(\Yii::$app->session->get('cr_content_filter'))){
                \Yii::$app->request->setQueryParams(['cr_content_filter' => \Yii::$app->session->get('cr_content_filter')]);
            }
        }

        if(key_exists('ctype', $_GET)) {
            \Yii::$app->session->set('ctype', $_GET['ctype']);
        } else {
            if(!empty(\Yii::$app->session->get('ctype'))){
                \Yii::$app->request->setQueryParams(['ctype' => \Yii::$app->session->get('ctype')]);
            }
        }

        $this->ctype = \Yii::$app->session->get('ctype');

        return parent::init();
    }

    /**
     * [actionIndex description]
     * @return [type] [description]
     */
    public function actionIndex() {
        $filter = null;

        if(!empty($_POST['selection'])) {
            foreach ($_POST['selection'] as $selection){
                $delModel = new CrelishDynamicJsonModel([], ['ctype'=>$this->ctype, 'uuid'=>$selection]);
                $delModel->delete();
            }
        }

        if (key_exists('cr_content_filter', $_GET)) {
            $filter = ['freesearch' => $_GET['cr_content_filter']];
        } else {
            if(!empty(\Yii::$app->session->get('cr_content_filter'))){
                $filter = ['freesearch' => \Yii::$app->session->get('cr_content_filter')];
            }
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
        $ctype = \Yii::$app->request->get('ctype');
        $uuid = \Yii::$app->request->get('uuid');

        $model = new CrelishDynamicJsonModel([], ['ctype'=>$ctype, 'uuid'=>$uuid]);
        $model->delete();
        $this->redirect('/crelish/content/index.html');
    }
}
