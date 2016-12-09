<?php
namespace giantbits\crelish\plugins\datainclude;

use giantbits\crelish\components\CrelishDynamicJsonModel;
use giantbits\crelish\components\CrelishJsonDataProvider;
use Underscore\Types\Arrays;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\grid\ActionColumn;

class DataInclude extends Widget
{
  public $data;
  public $formKey;
  public $field;

  public function init()
  {
    parent::init();

    if (!empty($this->data)) {
      $this->data = $this->processData($this->data);
    } else {
      $this->data = Json::encode([]);
    }
  }

  private function processData($data)
  {
    $processedData = [];
    $info = [];

    if(Arrays::has($data, 'ctype')) {
      $dataItem = new CrelishJsonDataProvider($data['ctype'], [], $data['uuid']);
      $itemData = $dataItem->one();

      foreach ($dataItem->definitions->fields as $field) {
        if ($field->visibleInGrid) {
          if (!empty($field->label) && !empty($itemData[$field->key])) {
            $info[] = ['label' => $field->label, 'value' => $itemData[$field->key]];
          }
        }
      }

      if (!empty($itemData['uuid']) && !empty($itemData['ctype'])) {
        $processedData = [
          'uuid' => $data['uuid'],
          'ctype' => $data['ctype'],
          'info' => $info
        ];
      }
    }

    return Json::encode($processedData);
  }

  public function run()
  {
    $elementType = !empty($_GET['cet']) ? $_GET['cet'] : 'page';
    $modelProvider = new CrelishJsonDataProvider($elementType, [], null);
    $filterModel = new CrelishDynamicJsonModel(['ctype' => $elementType]);

    $label = $this->field->label;

    $out = <<<EOT
    <div class="form-group field-crelishdynamicmodel-body required">
      <label class="control-label" for="crelishdynamicmodel-body">$label</label>
      <div class="">
        <datainclude_$this->formKey></datainclude_$this->formKey>
        <div class="help-block help-block-error "></div>
      </div>
    </div>

    <div class="modal fade matrix-modal-$this->formKey" tabindex="-1" role="dialog" aria-labelledby="matrix-modal-$this->formKey" id="matrix-modal-$this->formKey">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="myModalLabel">Content selection</h4>
          </div>
          <div class="modal-body">
EOT;


    $out .= $this->render('matrix.twig', [
      'dataProvider' => $modelProvider->raw(),
      'filterModel ' => $filterModel,
      'columns' => [
        'systitle', [
          'class' => ActionColumn::className(),
          'template' => '{update}',
          'buttons' => [
            'update' => function ($url, $model) {
              return Html::a('<span class="glyphicon glyphicon-plus"></span>', $url, [
                'title' => \Yii::t('app', 'Add'),
                'data-pjax' => '0',
                'data-content' => Json::encode(['uuid' => $model['uuid'], 'ctype' => $model['ctype']])
              ]);
            }
          ]
        ]
      ],
      'ctype' => $elementType,
      'formKey' => $this->formKey
    ]);

    $out .= <<<EOT
          </div>
        </div>
      </div>
    </div>

    <script type="riot/tag">
      <datainclude_$this->formKey>
        <div class="o-grid">
          <div class="o-grid__cell">
            { valueLabel }
            <div class="c-card__content c-card__content--divider c-heading">
              <span class="c-input-group pull-right">
                <button class="c-button gc-bc--palette-wetasphalt c-button--xsmall"><i class="glyphicon glyphicon-pencil"></i></button>
                <button class="c-button gc-bc--palette-pomgranate c-button--xsmall"><i class="glyphicon glyphicon-trash"></i></button>
              </span>
            </div>
            <div class="c-card__content">
              <dl>
                <span >
                  <dd>{ value }</dd>
                </span>
              </dl>
            </div>

            <button type="button" class="c-button c-button--ghost-primary c-button--block gc-mt--1" data-target=".matrix-modal-$this->formKey" onclick="openMatrixModal('{ item }')">Select content</button>
          </div>
        </div>
        <input type="hidden" name="CrelishDynamicJsonModel[$this->formKey]" id="CrelishDynamicJsonModel_$this->formKey" value="{ JSON.stringify(data) }" />

        // Logic goes here.
        var app = this
        app.data = opts.data
        if( app.data.info )
          app.valueLabel = app.data.info[0].value;
          
      </datainclude_$this->formKey>
    </script>

    <script>
      riot.mount('datainclude_$this->formKey', {
        data: $this->data
      });
    </script>
EOT;

    return $out;
  }
}
