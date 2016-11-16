<?php
namespace giantbits\crelish\widgets;

use yii\base\Widget;
use yii\helpers\Json;

class DataList extends Widget
{
  public $data;
  public $formKey;

  public function init()
  {
    parent::init();
  }

  public function run()
  {
    $formKey = $this->formKey;

    if(is_array($this->data)) {
      $data = (object) $this->data;
      $stringValue = Json::encode($this->data);
    } else {
      $data = new \stdClass();
    }

    $out = <<<EOT
    <div class="form-group field-crelishdynamicmodel-body required">
      <label class="control-label col-sm-3" for="crelishdynamicmodel-body">Data listing</label>
      <div class="col-sm-6">
      
        
        <div class="help-block help-block-error "></div>
        <input type="hidden" name="CrelishDynamicJsonModel[$formKey]" id="CrelishDynamicJsonModel_$formKey" value='$stringValue' />
      </div>
    </div>
    
    <script type="text/javascript">
      
    </script>
EOT;

    return $out;
  }
}
