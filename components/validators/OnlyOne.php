<?php
  
  namespace giantbits\crelish\components\validators;
  
  class OnlyOne extends \yii\validators\Validator
  {
    public function init()
    {
      parent::init();
      $this->message = Yii::t('i18n', 'validators.error.onlyone');
    }
    
    public function validateAttribute($model, $attribute)
    {
      var_dump('hier');
      
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