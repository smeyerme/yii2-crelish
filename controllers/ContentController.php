<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishDynamicJsonModel;
use giantbits\crelish\components\CrelishJsonDataProvider;
use giantbits\crelish\components\CrelishBaseController;
use yii\helpers\Url;
use yii\filters\AccessControl;

class ContentController extends CrelishBaseController
{
    public $layout = 'crelish.twig';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['create', 'index', 'delete'],
                'rules' => [
                    [
                        'allow' => true,
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
    public function init()
    {
        parent::init();

        $this->ctype = (!empty(\Yii::$app->getRequest()->getQueryParam('ctype'))) ? \Yii::$app->getRequest()->getQueryParam('ctype') : 'page';
        $this->uuid = (!empty(\Yii::$app->getRequest()->getQueryParam('uuid'))) ? \Yii::$app->getRequest()->getQueryParam('uuid') : null;
    }

    /**
     * [actionIndex description]
     * @return [type] [description]
     */
    public function actionIndex()
    {

        $filterProvider = new CrelishDynamicJsonModel([], ['ctype' => $this->ctype]);


        $filters = null;
        $sort = null;
        $limit = null;
        /*
                if (!empty($data['filter'])) {
                    foreach ($data['filter'] as $filter) {
                        if (is_array($filter)) {
                            $filters[key($filter)] = $filter[key($filter)];
                        } elseif (!empty($_GET[$filter])) {
                            $filters[$filter] = $_GET[$filter];
                        }
                    }
                }

                if (!empty($data['sort'])) {
                    $sort['by'] = (!empty($data['sort']['by'])) ? $data['sort']['by'] : null;
                    $sort['dir'] = (!empty($data['sort']['dir'])) ? $data['sort']['dir'] : null;
                }

                if (!empty($data['limit'])) {
                    if ($data['limit'] === false) {
                        $limit = 99999;
                    } else {
                        $limit = $data['limit'];
                    }
                }
                */

        if(!empty($_GET['CrelishDynamicJsonModel'])){
            foreach($_GET['CrelishDynamicJsonModel'] as $filter => $value) {
                if(!empty($value)){
                    $filters[$filter] = $value;
                }
            }
        }


        $modelProvider = new CrelishJsonDataProvider($this->ctype, ['filter'=>$filters], null);
        $columns = $modelProvider->columns;

        return $this->render('content.twig', [
            'dataProvider' => $modelProvider->raw(),
            'filterProvider' => $filterProvider,
            'columns' => $columns,
            'ctype' => $this->ctype,
        ]);
    }

    /**
     * [actionCreate description]
     * @return [type] [description]
     */
    public function actionCreate()
    {
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
    public function actionUpdate()
    {
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
    public function actionDelete()
    {
        $ctype = \Yii::$app->request->post('ctype');
        $uuid = \Yii::$app->request->post('uuid');

        // Build form for type.
        $filePath = \Yii::getAlias('@app/workspace/data/' . $ctype) . DIRECTORY_SEPARATOR . $uuid . '.json';

        $result = unlink($filePath); // or you can set for test -> false;
        $return_json = ['status' => 'error'];
        if ($result == true) {
            $return_json = ['status' => 'success', 'message' => 'successfully deleted', 'redirect' => Url::toRoute(['content/index'])];
        }
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        return $return_json;
    }
}
