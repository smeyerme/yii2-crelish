<?php

namespace giantbits\crelish\widgets;

use giantbits\crelish\components\CrelishDynamicModel;
use yii\base\Widget;
use yii\db\Exception;
use yii\helpers\FileHelper;
use yii\helpers\Html;
use yii\helpers\Json;
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

    parent::__construct();
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

    // Scan the elements directory for element definitions
    $elementsPath = \Yii::getAlias('@app/workspace/elements');
    $elements = [];
    
    try {
      $files = FileHelper::findFiles($elementsPath, ['only' => ['*.json']]);
      
      foreach ($files as $file) {
        $content = file_get_contents($file);
        $element = Json::decode($content, false);
        
        // Skip elements that are not selectable
        if (property_exists($element, 'selectable') && $element->selectable === false) {
          continue;
        }
        
        // Extract the element key from the filename
        $key = basename($file, '.json');
        $element->key = $key;
        
        $elements[] = [
          'key' => $key,
          'label' => property_exists($element, 'label') ? $element->label : $key,
          'selectable' => property_exists($element, 'selectable') ? $element->selectable : true
        ];
      }
      
      // Sort elements by label
      usort($elements, function($a, $b) {
        return strcmp($a['label'], $b['label']);
      });
    } catch (\Exception $e) {
      \Yii::error('Error scanning elements directory: ' . $e->getMessage());
    }

    $params[0] = 'content/selector';

    if(!isset($_GET['overlay'])){
      $params[0] = \Yii::$app->controller->id . '/' . $this->action;
    }

    foreach (\Yii::$app->getRequest()->getQueryParams() as $param => $value) {
      $params[$param] = $value;
    }

    foreach ($elements as $element) {
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
