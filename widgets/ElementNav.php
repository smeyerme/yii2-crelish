<?php

namespace giantbits\crelish\widgets;

use giantbits\crelish\components\CrelishDataProvider;
use yii\base\Widget;
use yii\db\Exception;
use yii\helpers\Html;
use yii\helpers\Url;

class ElementNav extends Widget
{
  public $message;
  private $action;
  private $ctype;
  private $selector = 'ctype';
	
	private $target = '#contentSelector';

  public function __construct($config = [])
  {
		if (count($config) > 0) {
      $this->action = (empty($config['action'])) ? \Yii::$app->controller->action->id : $config['action'];
      $this->selector = (empty($config['selector'])) ? $this->selector : $config['selector'];
      $this->ctype = (empty($config['ctype'])) ? $this->ctype : $config['ctype'];
      $this->target = (empty($config['target'])) ? $this->target : $config['target'];
    } else {
      $this->action = \Yii::$app->controller->action->id;
      $this->ctype = !empty(\Yii::$app->getRequest()
        ->getQueryParam($this->selector)) ? \Yii::$app->getRequest()
        ->getQueryParam($this->selector) : 'page';
    }

    parent::__construct(); // TODO: Change the autogenerated stub
  }

  public function init()
  {
    parent::init();
  }
	
	/**
	 * @throws Exception
	 */
	public function run()
  {
    $nav = '';
    $lastCat = '';

    $elements = new CrelishDataProvider('elements', [
      'key' => 'key',
      'sort' => ['by' => [ 'label', 'asc']],
      'limit' => 99
    ]);
		
    $params[0] = \Yii::$app->controller->id . '/' . $this->action;

    foreach (\Yii::$app->getRequest()->getQueryParams() as $param => $value) {
      $params[$param] = $value;
    }

    foreach ($elements->all()['models'] as $element) {

      if (array_key_exists('selectable', $element) && $element['selectable'] === FALSE) {
        continue;
      }

      $css = ($this->ctype == $element['key']) ? 'gc-active-filter' : '';
      $params[$this->selector] = $element['key'];
      $targetUrl = Url::to($params);
      $nav .= Html::tag('li', Html::a(Html::tag('span', $element['label']), $targetUrl, ['data-pjax'=>1, 'data-target'=>$this->target]), ['class' => $css]);
    }

    return $nav;
  }
}
