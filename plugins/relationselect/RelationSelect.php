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

  /*
  private function processData($data = null)
  {
    // Load datasource.
    if (is_array($this->predefinedOptions)) {
      foreach ($this->predefinedOptions as $option) {
        $dataItems[][$this->formKey] = $option;
      }
    } else {
      $dataSource = new CrelishDataProvider($this->relationDataType, ['sort' => ['by' => ['systitle', 'asc']]]);
      $dataItems = $dataSource->rawAll();
    }

    if(!empty($dataItems)) {
      foreach ($dataItems as $item) {

        if (!empty($item[$this->formKey])) {
          if (strpos($item[$this->formKey], ";") > 0) {
            foreach (explode("; ", $item[$this->formKey]) as $entry) {
              $this->selectData[$entry] = $entry;
            }
          } else {
            $this->selectData[$item[$this->formKey]] = $item[$this->formKey];
          }
        }
      }
    }

    return $data;
  }
*/

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
