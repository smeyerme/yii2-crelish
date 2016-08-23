<?php
namespace giantbits\crelish\widgets;

use giantbits\crelish\components\CrelishDynamicJsonModel;
use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\grid\ActionColumn;

class MatrixConnector extends Widget
{
  public $data;
  public $formKey;

  public function init()
  {
    parent::init();

    if(!empty($this->data)) {
      $this->data = $this->processData($this->data);
    } else {
      $this->data = Json::encode(['main' => []]);
    }
  }

  private function processData($data)
  {
    $processedData = [];

    foreach ($data as $key => $item){
      foreach ($item as $reference) {

        $info = [];
        $dataItem = new CrelishJsonDataProvider($reference['type'], [], $reference['uuid']);
        $itemData = $dataItem->one();

        foreach ($dataItem->definitions->fields as $field ) {
          if($field->visibleInGrid) {
            $info[] = ['label'=>$field->label, 'value'=> $itemData[$field->key]];
          }
        }

        $processedData[$key][] = [
          'uuid' => $reference['uuid'],
          'type' => $reference['type'],
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
    $filterModel = new CrelishDynamicJsonModel(['type' => $elementType]);

    $out = <<<EOT
    <div class="form-group field-crelishdynamicmodel-body required">
      <label class="control-label col-sm-3" for="crelishdynamicmodel-body">Matrix</label>
      <div class="col-sm-6">
        <todo></todo>
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
              'filterModel ' => $filterModel ,
              'columns' => [
                'systitle',[
                  'class' => ActionColumn::className(),
                  'template' => '{update}',
                  'buttons' => [
                    'update' => function ($url, $model) {
                      return Html::a('<span class="glyphicon glyphicon-plus"></span>', $url, [
                        'title' => \Yii::t('app', 'Add'),
                        'data-pjax' => '0',
                        'data-content' => Json::encode(['uuid' => $model['uuid'], 'type' => $model['type'] ])
                      ]);
                    }
                  ]
                ]
              ],
              'type' => $elementType,
              'formKey' => $this->formKey
            ]);

      $out .= <<<EOT
          </div>
        </div>
      </div>
    </div>

    <script type="riot/tag">
      <todo>
        <div class="o-grid">
          <div class="o-grid__cell" each={ item, i in data }>
            <span class="c-badge">{ item }</span>

            <div id="sortable">
            
              <div class="c-card" each={ i }>
                <div class="c-card__content c-card__content--divider c-heading"><span class="glyphicon glyphicon-move" aria-hidden="true"></span> { type }</div>
                <div class="c-card__content">
                  <dl>
                    <span each={ info }>
                      <dt>{ label }</dt>
                      <dd>{ value }</dd>
                    </span>
                  </dl>
                </div>
              </div>
              
            </div>
            <button type="button" class="c-button c-button--ghost-primary c-button--block gc-mt--1" data-target=".matrix-modal-$this->formKey" onclick="openMatrixModal('{ item }')">Add content</button>
          </div>
        </div>
        <input type="hidden" name="CrelishDynamicJsonModel[$this->formKey]" id="CrelishDynamicJsonModel_$this->formKey" value="{ JSON.stringify(data) }" />

        // Logic goes here.
        var app = this
        this.data = opts.data

        edit(e) {
          this.text = e.target.value
        }

        add(e) {
          if (this.text) {
            this.items.push({ title: this.text })
            this.text = this.input.value = ''
          }
        }

        removeAllDone(e) {
          this.items = this.items.filter(function(item) {
            return !item.done
          })
        }

        // an two example how to filter items on the list
        whatShow(item) {
          return !item.hidden
        }

        onlyDone(item) {
          return item.done
        }

        toggle(e) {
          var item = e.item
          item.done = !item.done
          return true
        }

        this.on("mount", function() {
          var that = this;
          var matrixData = this.data;
          var el = document.getElementById('sortable');
          if(el) {
            var sortable = Sortable.create(el, {
              handle: '.glyphicon-move',
              animation: 150,
              onSort: function (evt) {
                // same properties as onUpdate
                matrixData.main.move(evt.oldIndex, evt.newIndex)
                app.data = matrixData;
                app.update();
              }
            });
          }
        });
      </todo>
    </script>
    
    <script>
      var targetArea = 'main';
    
      var openMatrixModal = function( area ) {
        targetArea = area;
        $('.matrix-modal-$this->formKey').modal('show'); 
      };
    
      var activateContentMatrix = function() {
        $("#matrix-modal-$this->formKey a").each(function() {
          $(this).on('click', function(e) {
            e.preventDefault();
            var content = $(this).data("content");
            var origData = JSON.parse($("#CrelishDynamicJsonModel_$this->formKey").val());
            console.log($("#CrelishDynamicJsonModel_$this->formKey").val(), origData, targetArea);
            origData[targetArea].push( content );
            $("#CrelishDynamicJsonModel_$this->formKey").val(JSON.stringify(origData));
            
            $("#content-form").submit();
          });
        });
      };
    
      Array.prototype.move = function (old_index, new_index) {
        if (new_index >= this.length) {
          var k = new_index - this.length;
          while ((k--) + 1) {
            this.push(undefined);
          }
        }
        this.splice(new_index, 0, this.splice(old_index, 1)[0]);
        return this; // for testing purposes
      };
      
      $("#matrix-modal-$this->formKey").on("pjax:end", function() {
        activateContentMatrix();
      });
      
      $('#asset-modal').on('shown.bs.modal', function (e) {
        activateContentMatrix();
      });
      
      var tags = riot.mount('todo', {
        data: $this->data
      });
    </script>
EOT;

    return $out;
  }
}
