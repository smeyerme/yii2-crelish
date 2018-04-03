<?php

namespace giantbits\crelish\plugins\assetconnector;

use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishDataProvider;
use Underscore\Types\Arrays;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Json;

class AssetConnector extends Widget
{
  public $data;
  public $rawData;
  public $formKey;
  public $field;
  public $value;
  private $info;
  private $selectData = [];
  private $includeDataType = 'asset';

  public function init()
  {
    parent::init();

    if (!empty($this->data)) {
      $this->rawData = $this->data;
      $this->data = $this->processData($this->data);
    } else {
      $this->data = $this->processData(null);
      $this->rawData = [];
    }
  }

  private function processData($data)
  {
    if (is_string($data)) {
      $data = Json::decode($data);
    }

    $processedData = [];

    $typeDefinitions = CrelishDynamicModel::loadElementDefinition($this->includeDataType);

    if (Arrays::has($data, 'uuid')) {

      $itemData = new CrelishDynamicModel([], ['ctype' => $this->includeDataType, 'uuid' => $data['uuid']]);

      if (!empty($itemData['uuid'])) {
        $processedData = $itemData;
      }
    }

    // Load datasource.
    $dataSource = new CrelishDataProvider('asset', ['sort' => ['by' => ['created', 'desc']]]);
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

    return $processedData;
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

    $filter = null;
    if (!empty($_GET['cr_content_filter'])) {
      $filter = ['freesearch' => $_GET['cr_content_filter']];
    }

    $modelProvider = new CrelishDataProvider('asset', ['filter' => $filter], NULL);
    $modelColumns = $modelProvider->columns;

    $checkCol = [
      [
        'label' => \Yii::t('crelish', 'Preview'),
        'format' => 'raw',
        'value' => function ($model) {
          $preview = \Yii::t('crelish', 'n/a');

          switch ($model['mime']) {
            case 'image/jpeg':
            case 'image/gif':
            case 'image/png':
              $preview = Html::img($model['src'], ['style' => 'width: 80px; height: auto;']);
          }

          return $preview;
        }
      ]
    ];
    $columns = array_merge($checkCol, $modelColumns);

    $rowOptions = function ($model, $key, $index, $grid) {

      $onclick = "
      $('#asset_" . $this->formKey . "').val('" . $model['uuid'] . "'); 
      $('#asset-info-" . $this->formKey . "').html('" . $model['systitle'] . " (" . $model['mime'] . ")'); 
      $('#media-modal-" . $this->formKey . "').modal('hide');
      ";

      if(substr($model['mime'], 0, 5) == 'image') {
        $onclick .= "
        $('#asset-icon-" . $this->formKey . "').attr('src', '" . $model['src'] . "');  
        ";
      }

      return ['onclick' => $onclick];
    };

    return $this->render('assets.twig', [
      'dataProvider' => $modelProvider->raw(),
      'filterProvider' => $modelProvider->getFilters(),
      'columns' => $columns,
      'ctype' => 'asset',
      'rowOptions' => $rowOptions,
      'field' => $this->field,
      'rawData' => $this->data->uuid,
      'data' => $this->data,
      'formKey' => $this->formKey
    ]);
  }

  private function cleanRefData($data)
  {
    $cleanFields = ['uuid'];
    $cleanData['ctype'] = 'asset';

    foreach ($data as $index => $value) {
      if (in_array($index, $cleanFields)) {
        $cleanData[$index] = $value;
      }
    }

    return $cleanData;
  }
}
