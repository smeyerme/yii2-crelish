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

  public function init()
  {
    parent::init();
  }

  public function run()
  {
    $modelType = get_class($this->model);

    $fieldName = $modelType . "[" . $this->attribute . "]";
    $fieldId = $modelType . "_" . $this->attribute;
    $value = $this->model->{$this->attribute};

    $out = <<<EOT
    <div class="form-group field-crelishdynamicmodel-body required">
      <label class="control-label col-sm-3" for="crelishdynamicmodel-body">Widget</label>
      <div class="col-sm-6">

        <input type="hidden" name="$fieldName" id="$fieldId" value='$value' />
        <button type="button" class="btn btn-primary" data-toggle="modal" data-target=".bs-example-modal-lg">Select Widget</button>
        <div class="help-block help-block-error "></div>
      </div>
    </div>
EOT;

    return $out;
  }
}
