<?php
namespace giantbits\crelish\widgets;

use crelish\components\CrelishFileDataProvider;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\Link;

class ElementNav extends Widget
{
  public $message;

  public function init()
  {
    parent::init();
    if ($this->message === null) {
      $this->message = 'Hello World';
    }
  }

  public function run()
  {
    $nav = '';
    $elements = new CrelishFileDataProvider('elements', ['key' => 'key', 'sort'=> ['by'=>'label', 'dir'=>'ASC']]);

    foreach($elements->all()['models'] as $element) {
      $css = (!empty($_GET['type']) && $_GET['type'] == $element['key']) ? 'gc-active-filter' : '';
      $nav .= Html::tag('li', Html::a($element['label'], ['content/' . \Yii::$app->controller->action->id, 'type' => $element['key']]), ['class' => $css]);
    }

    return $nav;
  }
}