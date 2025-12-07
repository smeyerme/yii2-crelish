<?php

namespace giantbits\crelish\plugins\submodel;

use giantbits\crelish\components\CrelishArrayHelper;
use yii\base\Widget;

class SubModel extends Widget
{
  public $data;
  public $rawData;
  public $formKey;
  public $field;
  public $value;
  private $selectData = [];
  private $includeDataType;
  private $allowMultiple = false;
  private $hiddenValue = '';
  private $selectedValue;
  private $predefinedOptions;

  public function init()
  {
    parent::init();

    $this->includeDataType = $this->field->config->ctype;
    $this->allowMultiple = !empty($this->field->config->multiple) ? $this->field->config->multiple : false;
    $this->predefinedOptions = !empty($this->field->config->options) ? $this->field->config->options : null;

    if (!empty($this->data)) {
      if (strpos($this->data, ";") > 0) {
        $this->selectedValue = explode("; ", $this->data);
        $this->hiddenValue = $this->data;
      } else {
        $this->rawData = $this->data;
        $this->selectedValue = $this->data;
        $this->hiddenValue = $this->data;
      }
      $this->data = $this->processData($this->data);
    } else {
      $this->data = $this->processData();
      $this->rawData = "";
    }
  }

  private function processData($data = null)
  {
    return $data;
  }

  public function run()
  {
    $content = \Yii::$app->controller->buildForm('create', [
      'ctype' => $this->field->config->ctype,
      'prefix' => $this->formKey
    ]);

    $isRequired = CrelishArrayHelper::find($this->field->rules, function ($rule) {
      foreach ($rule as $set) {
        if ($set == 'required') {
          return true;
        }
      }
      return false;
    });

    return $this->render('submodel.twig', [
      'formKey' => $this->formKey,
      'field' => $this->field,
      'form' => $content
    ]);
  }
}
