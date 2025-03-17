<?php

namespace giantbits\crelish\plugins\assetconnector;

use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishDataProvider;
use giantbits\crelish\components\CrelishFormWidget;
use yii\helpers\Html;
use yii\helpers\Json;
use function _\find;

class AssetConnector extends CrelishFormWidget
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
    if(!is_object($data)) {
      if (substr($data, 0, 1) == '{' || substr($data, 0, 1) == '[') {
        $data = Json::decode($data);
      } else {
        $data = ['uuid' => $data];
      }
    }

    $processedData = [];

    $typeDefinitions = CrelishDynamicModel::loadElementDefinition($this->includeDataType);

    if (array_key_exists( 'uuid',(array) $data)) {

      $itemData = new CrelishDynamicModel( ['ctype' => $this->includeDataType, 'uuid' => $data['uuid']]);

      if (!empty($itemData['uuid'])) {
        $processedData = $itemData;
      }
    }

    // Load datasource.
    $dataSource = new CrelishDataProvider('asset', ['sort' => ['defaultOrder' => ['created' => SORT_DESC]]]);
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
    $isRequired = find($this->field->rules, function ($rule) {
      foreach ($rule as $set) {
        if ($set == 'required') {
          return true;
        }
      }
      return false;
    });

    $filter = null;
    if (!empty($_GET['cr_asset_filter'])) {
      $filter = ['freesearch' => $_GET['cr_asset_filter']];
    }

    $modelProvider = new CrelishDataProvider('asset', [
      'filter' => $filter,
      'sort' => ['defaultOrder' => ['created' => SORT_DESC]]
    ], NULL);
    $modelColumns = $modelProvider->columns;

    $checkCol = [
      [
        'label' => \Yii::t('app', 'Preview'),
        'format' => 'raw',
        'value' => function ($model) {
          $preview = \Yii::t('app', 'n/a');

          switch ($model['mime']) {
            case 'image/jpeg':
            case 'image/gif':
            case 'image/png':
              $preview = Html::img('/crelish/asset/glide?path=' . $model['fileName'] . '&w=160&f=fit', ['style' => 'width: 80px; height: auto;']);
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
    
    //$removalUrl =  !empty(\Yii::$app->request->get('uuid')) ? Url::to(['', 'ctype' => \Yii::$app->request->get('ctype'), 'uuid' => \Yii::$app->request->get('uuid'), 'remAsset' => $this->field->key]) : null;

    return $this->render('assets.twig', [
      'dataProvider' => $modelProvider->getProvider(),
      'filterProvider' => $modelProvider->getFilters(),
      'columns' => $columns,
      'ctype' => 'asset',
      'rowOptions' => $rowOptions,
      'field' => $this->field,
      'rawData' => !empty($this->data->uuid) ? $this->data->uuid : '',
      'data' => $this->data,
      'formKey' => $this->formKey,
      //'removalUrl' => $removalUrl
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
