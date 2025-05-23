<?php

namespace giantbits\crelish\plugins\matrixconnector;

use giantbits\crelish\components\CrelishDataManager;
use giantbits\crelish\components\CrelishDataResolver;
use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishDataProvider;
use Yii;
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

    // Get the AssetManager instance
    $assetManager = Yii::$app->assetManager;

    // Define the source path of your JS file
    $sourcePath = Yii::getAlias('@vendor/giantbits/yii2-crelish/resources/pagebuilder/dist/page-builder.js');

    // Publish the file and get the published URL
    $publishedUrl = $assetManager->publish($sourcePath, [
      'forceCopy' => YII_DEBUG,
      'appendTimestamp' => true,
    ])[1];

    // Register the script in the view
    $this->view->registerJsFile($publishedUrl);
  }

  public function run()
  {

    $elementType = !empty($_GET['cet']) ? $_GET['cet'] : 'page';
    $modelProvider = CrelishDataResolver::resolveProvider($elementType, []);
    $attributes = ['ctype' => $elementType];
    $filterModel = new CrelishDynamicModel($attributes);

    return $this->render('matrix.twig', [
      'dataProvider' => method_exists($modelProvider, 'getProvider') ? $modelProvider->getProvider() : $modelProvider,
      'filterModel ' => $filterModel,
      'columns' => [
        'systitle',
        [
          'class' => ActionColumn::class,
          'template' => '{update}',
          'buttons' => [
            'update' => function ($url, $model, $elementType) {
              if (!is_array($model)) {
                $ctype = explode('\\', strtolower($model::class));
                $ctype = end($ctype);
              } else {
                $ctype = $elementType;
              }

              return Html::a('<span class="fa-sharp  fa-regular fa-plus"></span>', '', [
                'title' => \Yii::t('app', 'Add'),
                'data-pjax' => '0',
                'data-content' => Json::encode(
                  [
                    'uuid' => is_array($model) ? $model['uuid'] : $model->uuid,
                    'ctype' => is_array($model) ? $model['ctype'] : $model->ctype,
                    'info' => [
                      [
                        'label' => \Yii::t('app', 'Titel intern'),
                        'value' => is_array($model) ? $model['systitle'] : $model->systitle
                      ],
                      [
                        'label' => \Yii::t('app', 'Status'),
                        'value' => is_array($model) ? $model['state'] : $model->state
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
      if (!is_string($item)) {
        foreach ($item as $reference) {
          $info = [];

          $dataItem = new CrelishDataManager($reference['ctype'], $settings = [], $reference['uuid']);

          foreach ($dataItem->definitions->fields as $field) {
            if (isset($field->visibleInGrid) && $field->visibleInGrid) {
              if (!empty($field->label) && !empty($itemData[$field->key])) {

                if ($field && property_exists($field, 'transform')) {
                  $transformer = 'giantbits\crelish\components\transformer\CrelishFieldTransformer' . ucfirst($field->transform);
                  if (class_exists($transformer) && method_exists($transformer, 'afterFind')) {
                    $transformer::afterFind($itemData[$field->key]);
                  }
                }

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
      } else {
        $processedData[$key] = $item;
      }
    }

    return Json::encode($processedData);
  }
}
