<?php
namespace giantbits\crelish\widgets;

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
    $cs = '
      var closeFlash = function() {
        $("#crelish-message-drawer").remove();
        $(".c-overlay").remove();
      };

      $("#crelish-message-drawer .drawer-closer").on("click", function(e) {
        closeFlash();
      });

      setTimeout(closeFlash, 2500);
    ';

    \Yii::$app->view->registerJs($cs, View::POS_END, 'message-drawer');
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

    $renderHtml = '';

    $renderHtml .= '<div id="crelish-message-drawer" class="o-drawer u-highest o-drawer--top o-drawer--visible">';
    $renderHtml .= '<div class="c-card">';
    $renderHtml .= '<header class="c-card__header" style="text-align: right;"><button class="c-button c-button--ghost drawer-closer">×</button></header>';
    $renderHtml .= '<div class="c-card__body">';

    foreach ($this->messages as $key => $message) {
      $renderHtml .= Html::beginTag('div', ['class'=>'c-alert c-alert--' . $key] );
      $renderHtml .= $message;
      $renderHtml .= Html::endTag('div');
    }

    $renderHtml .= '</div>';
    $renderHtml .= '</div>';
    $renderHtml .= '</div>';
    $renderHtml .= '<div class="c-overlay c-overlay--dismissable"></div>';

    return $renderHtml;
  }
}
