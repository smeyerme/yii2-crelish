<?php

namespace giantbits\crelish\plugins\relationselect;

use giantbits\crelish\components\CrelishDataProvider;
use Underscore\Types\Arrays;
use yii\base\Widget;

class RelationSelect extends Widget
{
  public $data;
  public $rawData;
  public $formKey;
  public $field;
  public $value;
  private $selectData = [];
  private $relationDataType;
  private $allowMultiple = false;
  private $hiddenValue = '';
  private $selectedValue;
  private $predefinedOptions;

  public function init()
  {
    parent::init();

    // Set related ctype.
    $this->relationDataType = $this->field->config->ctype;
    // Fetch options.
    $optionProvider = new CrelishDataProvider($this->relationDataType, ['filter'=>['state'=>['strict', 2]]]);

    $options = [];
    foreach($optionProvider->rawAll() as $option){
      $options[$option['uuid']] = $option['systitle'];
    }
    $this->predefinedOptions = $options;
  }

  public function run()
  {

    $isRequired = Arrays::find($this->field->rules, function ($rule) {
      foreach ($rule as $set) {
        if ($set == 'required') {
          return true;
        }
      }
      return false;
    });

    return $this->render('relationselect.twig', [
      'formKey' => $this->formKey,
      'field' => $this->field,
      'required' => ($isRequired) ? 'required' : '',
      'selectData' => $this->predefinedOptions,
      'selectValue' => isset($this->data->uuid) ? $this->data->uuid : '',
      'hiddenValue' => isset($this->data->uuid) ? $this->data->uuid :''
    ]);
  }
}
