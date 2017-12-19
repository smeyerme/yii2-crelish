<?php

namespace giantbits\crelish\plugins\datainclude;

use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishDataProvider;
use Underscore\Types\Arrays;
use yii\base\Widget;
use yii\helpers\Json;

class DataInclude extends Widget
{
  public $data;
  public $rawData;
  public $formKey;
  public $field;
  public $value;
  private $info;
  private $selectData = [];
  private $includeDataType;

  public function init()
  {
    parent::init();

    $this->includeDataType = $this->field->config->ctype;

    if (!empty($this->data)) {

      if(strpos($this->data, "{") !== false) {
        $rawData = Json::decode($this->data);
      } else {
        $rawData = $this->data;
      }

      $this->rawData = $rawData;
      $this->data = $this->processData($this->data);
    } else {
      $this->data = $this->processData("");
      $this->rawData = "";
    }
  }

  private function processData($data)
  {
    $processedData = [];

    $typeDefinitions = CrelishDynamicModel::loadElementDefinition($this->includeDataType);

    if (Arrays::has($data, 'uuid')) {

      $itemData = new CrelishDynamicModel([], ['ctype' => $this->includeDataType, 'uuid' => $data['uuid']]);

      if (!empty($itemData['uuid'])) {
        $processedData = [
          'uuid' => $data['uuid'],
          'ctype' => $this->includeDataType,
        ];
      }
    }

    // Load datasource.
    $dataSource = new CrelishDataProvider($this->includeDataType, ['sort' => ['by' => ['systitle', 'asc']]]);
    $dataSource = $dataSource->rawAll();

    foreach ($dataSource as $entry) {
      $this->selectData[$entry['uuid']] = $entry['systitle'];
    }

    foreach ($typeDefinitions->fields as $field) {
      if ($field->visibleInGrid) {
        if (!empty($field->label) && !empty($itemData[$field->key])) {
          $this->info[$field->key] = $itemData[$field->key];
        }
      }
    }

    return Json::encode($processedData);
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

    return $this->render('datainclude.twig', [
      'formKey' => $this->formKey,
      'field' => $this->field,
      'required' => ($isRequired) ? 'required' : '',
      'rawData' => Json::encode($this->rawData),
      'selectData' => $this->selectData,
      'selectValue' => (!empty($this->rawData['uuid'])) ? $this->rawData['uuid'] : '',
      'info' => $this->info,
      'includeDataType' => $this->includeDataType
    ]);
  }
}
