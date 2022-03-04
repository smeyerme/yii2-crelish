<?php

namespace giantbits\crelish\plugins\matrixconnector;

use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishDataProvider;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\grid\ActionColumn;

class MatrixConnector extends Widget
{
  public $data;
  public $formKey;
  public $field;
  public $model;

  public function init()
  {
    parent::init();

    if (!empty($this->data)) {
      $this->data = $this->processData($this->data);
    } else {
      $this->data = Json::encode(['main' => []]);
    }
  }

  public function run()
  {
    $elementType = !empty($_GET['cet']) ? $_GET['cet'] : 'page';
    $modelProvider = new CrelishDataProvider($elementType, [], null);
    $filterModel = new CrelishDynamicModel(['ctype' => $elementType]);

    return $this->render('matrix.twig', [
      'dataProvider' => $modelProvider->raw(),
      'filterModel ' => $filterModel,
      'columns' => [
        'systitle',
        [
          'class' => ActionColumn::class,
          'template' => '{update}',
          'buttons' => [
            'update' => function ($url, $model) {
              return Html::a('<span class="glyphicon glyphicon-plus"></span>', '', [
                'title' => \Yii::t('app', 'Add'),
                'data-pjax' => '0',
                'data-content' => Json::encode(
                  [
                    'uuid' => $model['uuid'],
                    'ctype' => $model['ctype'],
                    'info' => [
                      [
                        'label' => \Yii::t('app', 'Titel intern'),
                        'value' => $model['systitle']
                      ],
                      [
                        'label' => \Yii::t('app', 'Status'),
                        'value' => $model['state']
                      ]
                    ]
                  ]),
                'class' => 'cntAdd'
              ]);
            }
          ]
        ]
      ],
      'ctype' => $elementType,
      'formKey' => $this->formKey,
      'label' => $this->field->label,
      'processedData' => $this->data
    ]);
  }

  private function processData($data)
  {

    if (is_string($data)) {
      $data = Json::decode($data);
    }

    $processedData = [];

    foreach ($data as $key => $item) {

      $processedData[$key] = [];

      foreach ($item as $reference) {

        $info = [];
        $dataItem = new CrelishDataProvider($reference['ctype'], [], $reference['uuid']);
        $itemData = $dataItem->one();

        foreach ($dataItem->definitions->fields as $field) {
          if (isset($field->visibleInGrid) && $field->visibleInGrid) {
            if (!empty($field->label) && !empty($itemData[$field->key])) {
              $info[] = ['label' => $field->label, 'value' => $itemData[$field->key]];
            }
          }
        }

        $processedData[$key][] = [
          'area' => $key,
          'uuid' => $reference['uuid'],
          'ctype' => $reference['ctype'],
          'info' => $info
        ];
      }
    }

    return Json::encode($processedData);
  }
}
