<?php
namespace giantbits\crelish\widgets;

use kartik\widgets\AlertBlock;
use yii\web\View;
use yii\base\Widget;
use yii\helpers\Html;

class FlashMessages extends Widget
{
  /**
   * [$messages description]
   * @var [type]
   */
  private $messages;

  /**
   * [init description]
   * @return [type] [description]
   */
  public function init()
  {
    $this->messages = \Yii::$app->session->getAllFlashes();
    $this->buildClientScript();
    parent::init();
  }

  /**
   * [buildClientScript description]
   * @return [type] [description]
   */
  private function buildClientScript()
  {
    $cs = '//nothing';

    \Yii::$app->view->registerJs($cs, View::POS_END, 'crelish-messages');
  }

  /**
  * [run description]
  * @return [type] [description]
  */
  public function run()
  {
    if(sizeof($this->messages) === 0) {
      return;
    }

    ;

    echo AlertBlock::widget([
      'useSessionFlash' => true,
      'type' => AlertBlock::TYPE_ALERT,
      'delay' => 2500,
      'useSessionFlash' => true
    ]);

    foreach ($this->messages as $key => $message) {
    }
  }
}
