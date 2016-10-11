<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishJsonDataProvider;
use giantbits\crelish\components\CrelishDynamicJsonModel;
use giantbits\crelish\widgets\MatrixConnector;
use giantbits\crelish\widgets\AssetConnector;
use giantbits\crelish\widgets\DataList;
use giantbits\crelish\plugins;
use yii\web\Controller;
use yii\bootstrap\ActiveForm;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\helpers\Html;

class ContentController extends Controller
{
    private $ctype, $uuid, $filePath, $elementDefinition, $model;
    public $layout = 'crelish.twig';

    public function init()
    {
      parent::init();

      $this->ctype = (!empty(\Yii::$app->getRequest()->getQueryParam('ctype'))) ? \Yii::$app->getRequest()->getQueryParam('ctype') : 'page';
      $this->uuid = (!empty(\Yii::$app->getRequest()->getQueryParam('uuid'))) ? \Yii::$app->getRequest()->getQueryParam('uuid') : null;
    }

    public function actionIndex()
    {
      $modelProvider = new CrelishJsonDataProvider($this->ctype, [], null);

      return $this->render('content.twig', [
        'dataProvider' => $modelProvider->raw(),
        'columns' => $modelProvider->columns,
        'ctype' => $this->ctype,
      ]);
    }

    public function actionCreate()
    {
      $content = $this->buildForm();

      return $this->render('create.twig', [
        'content' => $content,
        'ctype' => $this->ctype,
        'uuid' => $this->uuid,
      ]);
    }

    public function actionUpdate()
    {
      $content = $this->buildForm();

      return $this->render('create.twig', [
        'content' => $content,
        'ctype' => $this->ctype,
        'uuid' => $this->uuid,
      ]);
    }

    public function actionDelete()
    {
      $ctype = \Yii::$app->request->post('ctype');
      $uuid = \Yii::$app->request->post('uuid');

      // Build form for type.
      $filePath = \Yii::getAlias('@app/workspace/data/'.$ctype).DIRECTORY_SEPARATOR.$uuid.'.json';

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
      $this->model = new CrelishDynamicJsonModel([], ['ctype' => $this->ctype, 'uuid' => $this->uuid]);

      // Save content if post request.
      if (!empty(\Yii::$app->request->post()) && !\Yii::$app->request->isAjax) {
          $oldData = [];
      // Load old data.
      if (!empty($this->model->uuid)) {
          $oldData = Json::decode(file_get_contents(\Yii::getAlias('@app/workspace/data/').DIRECTORY_SEPARATOR.$this->ctype.DIRECTORY_SEPARATOR.$this->model->uuid.'.json'));
      }

      $this->model->attributes = $_POST['CrelishDynamicJsonModel'] + $oldData;

      if ($this->model->validate()) {
          $this->model->save();
          \Yii::$app->session->setFlash('success', 'Content saved successfully...');
          header('Location: '.Url::to(['content/update', 'ctype' => $this->ctype, 'uuid' => $this->model->uuid]));
          exit(0);
      } else {
          $errors = $this->model->errors;
      }
    }

    ob_start();
    $form = ActiveForm::begin([
      'id' => 'content-form',
      //'layout' => 'horizontal',
    ]);

    // Start output.
    echo '<div class="gc-bc--palette-clouds gc-bs--soft gc-ptb--2">';

    // Display messages.
    foreach (\Yii::$app->session->getAllFlashes() as $key => $message) {
      echo '<div class="c-alerts__alert c-alerts__alert--'.$key.'">'.$message.'</div>';
    }

    echo Html::beginTag("div", ['class'=>'o-grid']);

    // TODO: This has to be dynamicaly handled like it's done in frontend.
    //  Also the tabs and grouping mechanics have to be implemented.

    // Get the tabs (there has to be at least one).
    $tabs = $this->model->fieldDefinitions->tabs;

    //var_dump($tabs);
    foreach($tabs as $tab) {
      // Loop through tabs.

      foreach($tab->groups as $group) {
        // Loop through groups.
        $widthClass = (!empty($group->settings->width)) ? 'o-grid__cell--width-' . $group->settings->width : '';

        echo Html::beginTag('div', ['class'=>'o-grid__cell ' . $widthClass]);
        echo Html::beginTag('div', ['class'=>'c-card']);
        echo Html::tag('div', $group->label , ['class'=>'c-card__item c-card__item--divider']);
        echo Html::beginTag('div', ['class'=>'c-card__item']);

        foreach ($this->model->fieldDefinitions->fields as $field) {

          if(!in_array($field->key, $group->fields)) {
            continue;
          }

          // Build form fields.
          $fieldOptions = !empty($field->options) ? $field->options : [];

          if (strpos($field->type, 'widget_') !== false) {
            $widget = str_replace('widget_', '', $field->type);
            echo $form->field($this->model, $field->key)->widget($widget::className())->label($field->label);
          } elseif ($field->type == 'dropDownList') {
            echo $form->field($this->model, $field->key)->{$field->type}((array) $field->items, (array) $fieldOptions)->label($field->label);
          } elseif ($field->type == 'matrixConnector') {
            echo plugins\matrixconnector\MatrixConnector::widget(['formKey' => $field->key, 'data' => $this->model{$field->key}]);
          } elseif ($field->type == 'assetConnector') {
            echo plugins\assetconnector\AssetConnector::widget(['formKey' => $field->key, 'data' => $this->model{$field->key}]);
          } elseif ($field->type == 'dataList') {
            echo DataList::widget(['formKey' => $field->key, 'data' => $this->model{$field->key}]);
          } else {
            echo $form->field($this->model, $field->key)->{$field->type}((array) $fieldOptions)->label($field->label);
          }
        }

        echo Html::endTag('div');
        echo Html::endTag('div');
        echo Html::endTag('div');
      }

    }

    echo Html::endTag('div');



    echo '</div>';
    ActiveForm::end();

    return ob_get_clean();
  }
}
