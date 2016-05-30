<?php
namespace giantbits\crelish\widgets;

use crelish\components\CrelishFileDataProvider;
use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\web\Link;

class MatrixConnetor extends Widget
{
  private $data;

  public function __construct($config = [])
  {

    if(!empty($config['data'])) {
      $this->processData($config['data']);
    }

    parent::__construct();
  }

  private function processData($data)
  {
    $processedData = [];

    foreach ($data as $key => $item){
      foreach ($item as $reference) {

        $info = [];
        $dataItem = new CrelishJsonDataProvider($reference['type'], [], $reference['uuid']);
        $itemData = $dataItem->one();

        foreach ($dataItem->definitions()->fields as $field ) {
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

    $this->data = Json::encode($processedData);
  }

  public function run()
  {
    $out = <<<EOT
    <div class="form-group field-crelishdynamicmodel-body required">
      <label class="control-label col-sm-3" for="crelishdynamicmodel-body">Matrix</label>
      <div class="col-sm-6">
        <todo></todo>
        <div class="help-block help-block-error "></div>
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
                        
          </div>
        </div>
        <input type="hidden" name="CrelishDynamicModel[matrix]" id="CrelishDynamicModel_matrix" value="{ JSON.stringify(data) }" />
        
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
          
          var matrixData = this.data;
          var el = document.getElementById('sortable');
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
        });
      </todo>
    </script>
    <script>
      var tags = riot.mount('todo', {
        data: $this->data
      });
    </script>
EOT;

    return $out;
  }
}