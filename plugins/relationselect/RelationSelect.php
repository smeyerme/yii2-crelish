<?php

namespace giantbits\crelish\plugins\relationselect;

use giantbits\crelish\components\CrelishDataProvider;
use giantbits\crelish\components\CrelishFormWidget;
use Yii;
use yii\data\ArrayDataProvider;
use yii\helpers\Html;
use yii\helpers\Url;
use function _\find;
use function _\orderBy;

class RelationSelect extends CrelishFormWidget
{
  public $data;
  public $rawData;
  public $formKey;
  public $field;
  public $value;
  public $attribute;
  public $allowClear = false;

  private $relationDataType;
  private $predefinedOptions;

  public function init()
  {
    parent::init();

    $customLabel = null;

    // Set related ctype.
    $this->relationDataType = '\app\workspace\models\\' . ucfirst($this->field->config->ctype);

    if (!empty($this->field->config->dataLabel)) {
      $customLabel = $this->field->config->dataLabel;
    }

    // Fetch options.
    $optionProvider = $this->relationDataType::find()->asArray()->all();

    $options = [];
    foreach ($optionProvider as $option) {
      if($customLabel) {
        $options[$option['uuid']] = $option[$customLabel];
      } else {
        $options[$option['uuid']] = !empty($option['systitle']) ? $option['systitle'] : $option['uuid'];
      }
    }
	  
	  asort($options);

    $this->predefinedOptions = $options;
    $ul = Yii::$app->request->get('ul');
    
    if ($ul) {
      // Todo: Get type of parent + uuid. Load parent. Unlink subelement.
      //$ownerCtype = \Yii::$app->request->get('ctype');
      //$ownerUuid = \Yii::$app->request->get('uuid');
      $childCtype = str_replace('_list', null, explode('::', $ul)[0]);
      $childUuid = explode('::', $ul)[1];

      $child = call_user_func('app\workspace\models\\' . ucfirst($this->field->config->ctype) . '::find')->where(['uuid' => $childUuid])->one();
      $owner = call_user_func('app\workspace\models\\' . ucfirst($this->model->ctype) . '::find')->where(['uuid' => $this->model->uuid])->one();

      if ($owner && $child) {
        $owner->unlink($childCtype, $child, true);
      }
      Yii::$app->response->redirect(Url::current(['ul' => null]));
    }

  }

  public function run()
  {
    $itemList = $itemListColumns = [];
    $tagMode = true;
    $isRequired = find($this->field->rules, function ($rule) {
      foreach ($rule as $set) {
        if ($set == 'required') {
          return true;
        }
      }
      return false;
    });

    if (isset($this->field->config->autocreate) && !$this->field->config->autocreate) {
      $tagMode = false;
    }

    if (isset($this->field->config->multiple) && $this->field->config->multiple) {
      $tagMode = false;
      // Load related data.
      $ar = call_user_func('app\workspace\models\\' . ucfirst($this->model->ctype) . '::find')->where(['uuid' => $this->model->uuid])->one();
      //$itemList = new ArrayDataProvider();
      if ($ar) {
        $itemList = new ArrayDataProvider(['allModels' => $ar->{str_replace('_list', null, $this->field->key)}]);
      }

      $actionCol = [
        [
          'format' => 'raw',
          'value' => function ($data) {
            $url = Yii::$app->request->absoluteUrl . '&ul=' . $this->field->key . '::' . $data->uuid;
            return Html::a('<i class="fa-sharp fa-regular  fa-trash"></i>', $url, ['title' => 'Löschen', 'class' => 'c-button u-small']);
          }
        ]
      ];

      $itemListColumns = array_merge($this->field->config->columns, $actionCol);
    }

    // Check for true models


    return $this->render('relationselect.twig', [
      'formKey' => $this->formKey,
      'field' => $this->field,
      'required' => ($isRequired) ? 'required' : '',
      'selectData' => $this->predefinedOptions,
      'selectValue' => is_object($this->data) ? $this->data->uuid : $this->data,
      'hiddenValue' => is_object($this->data) ? $this->data->uuid : $this->data,
      'tagMode' => $tagMode,
      'itemlist' => $itemList,
      'itemlistcolumns' => $itemListColumns,
      'allowClear' => $this->allowClear,
    ]);
  }
}
