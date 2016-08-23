<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 17:19
 */

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishJsonDataProvider;
use giantbits\crelish\components\CrelishDynamicJsonModel;
use giantbits\crelish\widgets\MatrixConnector;
use giantbits\crelish\widgets\AssetConnector;
use giantbits\crelish\widgets\DataList;
use yii\web\Controller;
use yii\bootstrap\ActiveForm;
use yii\helpers\Json;
use yii\helpers\Url;

class ContentController extends Controller
{
    private $type, $uuid, $filePath, $elementDefinition, $model;
    public $layout = 'crelish.twig';

    public function init()
    {
        parent::init(); // TODO: Change the autogenerated stub

        $this->type = (!empty(\Yii::$app->getRequest()->getQueryParam('type'))) ? \Yii::$app->getRequest()->getQueryParam('type') : 'page';
        $this->uuid = (!empty(\Yii::$app->getRequest()->getQueryParam('uuid'))) ? \Yii::$app->getRequest()->getQueryParam('uuid') : null;

    }

    public function actionIndex()
    {
        $modelProvider = new CrelishJsonDataProvider($this->type, [], null);

        return $this->render('content.twig', [
            'dataProvider' => $modelProvider->raw(),
            'columns' => $modelProvider->columns,
            'type' => $this->type
        ]);
    }

    public function actionCreate()
    {
        $content = $this->buildForm();

        return $this->render('create.twig', [
            'content' => $content,
            'type' => $this->type,
            'uuid' => $this->uuid
        ]);
    }

    public function actionUpdate()
    {
        $content = $this->buildForm();

        return $this->render('create.twig', [
            'content' => $content,
            'type' => $this->type,
            'uuid' => $this->uuid
        ]);
    }

    public function actionDelete()
    {
        $type = \Yii::$app->request->post('type');
        $uuid = \Yii::$app->request->post('uuid');

        // Build form for type.
        $filePath = \Yii::getAlias('@app/workspace/data/' . $type) . DIRECTORY_SEPARATOR . $uuid . '.json';

        $result = unlink($filePath); // or you can set for test -> false;
        $return_json = ['status' => 'error'];
        if ($result == true) {
            $return_json = ['status' => 'success', 'message' => 'successfully deleted', 'redirect' => Url::toRoute(['content/index'])];
        }
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        return $return_json;
    }

    private function buildForm($action = 'update')
    {
        // Build form for type.
        $this->model = new CrelishDynamicJsonModel([], ['type' => $this->type, 'uuid' => $this->uuid]);

        // Save content if post request.
        if (!empty(\Yii::$app->request->post()) && !\Yii::$app->request->isAjax) {
            $oldData = [];
            // Load old data.
            if (!empty($this->model->uuid)) {
                $oldData = Json::decode(file_get_contents(\Yii::getAlias('@app/workspace/data/') . DIRECTORY_SEPARATOR . $this->type . DIRECTORY_SEPARATOR . $this->model->uuid . '.json'));
            }
            $this->model->attributes = $_POST['CrelishDynamicJsonModel'] + $oldData;

            if ($this->model->validate()) {
                $this->model->save();
                \Yii::$app->session->setFlash('success', 'Content saved successfully...');
                header("Location: " . Url::to(['content/update', 'type' => $this->type, 'uuid' => $this->model->uuid]));
                exit(0);
            } else {
                $errors = $this->model->errors;
            }
        }

        ob_start();
        $form = ActiveForm::begin([
            'id' => 'content-form',
            'layout' => 'horizontal'
        ]);

        // Start output.
        echo '<div class="gc-bc--palette-clouds gc-bs--soft">';

        // Display messages.
        foreach (\Yii::$app->session->getAllFlashes() as $key => $message) {
            echo '<div class="c-alerts__alert c-alerts__alert--' . $key . '">' . $message . '</div>';
        }

        // Build form fields.
        foreach ($this->model->fieldDefinitions->fields as $field) {
            $fieldOptions = !empty($field->options) ? $field->options : [];

            if (strpos($field->type, 'widget_') !== false) {
                $widget = str_replace("widget_", '', $field->type);
                echo $form->field($this->model, $field->key)->widget($widget::className())->label($field->label);
            } elseif ($field->type == 'dropDownList') {
                echo $form->field($this->model, $field->key)->{$field->type}((array)$field->items, (array)$fieldOptions)->label($field->label);
            } elseif ($field->type == 'matrixConnector') {
                echo MatrixConnector::widget(['formKey' => $field->key, 'data' => $this->model{$field->key}]);
            } elseif ($field->type == 'assetConnector') {
                echo AssetConnector::widget(['formKey' => $field->key, 'data' => $this->model{$field->key}]);
            } elseif ($field->type == 'dataList') {
                echo DataList::widget(['formKey' => $field->key, 'data' => $this->model{$field->key}]);
            } else {
                echo $form->field($this->model, $field->key)->{$field->type}((array)$fieldOptions)->label($field->label);
            }
        }

        echo '</div>';
        ActiveForm::end();

        return ob_get_clean();
    }
}
