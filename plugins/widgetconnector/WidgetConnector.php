<?php
namespace giantbits\crelish\plugins\widgetconnector;

use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Widget;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\helpers\VarDumper;

class WidgetConnector extends Widget
{
  public $model;
  public $attribute;
  public $data;
  public $formKey;
  public $field;

  public function init()
  {
    parent::init();
  }

  public function run()
  {
  
		$modelType = get_class($this->model);

    $fieldName = $modelType . "[" . $this->attribute . "]";
    $fieldId = $modelType . "_" . $this->attribute;
    $value = (!empty($this->data)) ? $this->data : '';

    $out = <<<EOT
    <div class="form-group field-crelishdynamicmodel-body required">
      <label class="control-label" for="crelishdynamicmodel-body">Widget</label>
      <div>
        <input type="text" class="form-control" name="CrelishDynamicModel[$this->formKey]" id="CrelishDynamicModel_$this->formKey" value="$value" />
      </div>
    </div>
EOT;

    return $out;
  }
}
