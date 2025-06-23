<?php
namespace giantbits\crelish\plugins\widgetconnector;

use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Widget;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\helpers\VarDumper;
use Yii;

/**
 * WidgetConnector V1 Widget
 * 
 * @deprecated Use WidgetConnectorV2 instead for better integration and features
 * @see \giantbits\crelish\plugins\widgetconnector\WidgetConnectorV2
 */
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
    
    // Log deprecation notice
    if (YII_DEBUG) {
      Yii::warning('WidgetConnector V1 is deprecated. Please migrate to WidgetConnectorV2 for better features and JsonStructureEditor support.', 'crelish.deprecated');
    }
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
