<?php
  
  namespace giantbits\crelish\components\validators;
  
  use Yii;
  
  class OnlyOne extends \yii\validators\Validator
  {
    public function init(): void
    {
      parent::init();
      $this->message = Yii::t('i18n', 'validators.error.onlyone');
    }
    
    public function validateAttribute($model, $attribute)
    {
			
    }
    
    public function clientValidateAttribute($model, $attribute, $view)
    {
      
      $message = json_encode($this->message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      return <<<JS

if (parseInt($("#testform-age").val())<18) {
    messages.push($message);
}
JS;
    }
  }
