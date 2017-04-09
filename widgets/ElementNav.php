<?php
namespace giantbits\crelish\widgets;

use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\Link;

class ElementNav extends Widget {
    public $message;
    private $action;
    private $ctype;
    private $selector = 'ctype';

    public function __construct($config = []) {
        if (count($config) > 0) {
            $this->action = (empty($config['action'])) ? \Yii::$app->controller->action->id : $config['action'];
            $this->selector = (empty($config['selector'])) ? 'ctype' : $config['selector'];
            $this->ctype = (empty($config[$this->selector])) ? '' : $config[$this->selector];
        }
        else {
            $this->action = \Yii::$app->controller->action->id;
            $this->ctype = !empty(\Yii::$app->getRequest()
                ->getQueryParam($this->selector)) ? \Yii::$app->getRequest()
                ->getQueryParam($this->selector) : 'page';
        }

        parent::__construct(); // TODO: Change the autogenerated stub
    }

    public function init() {
        parent::init();
        if ($this->message === NULL) {
            $this->message = 'Hello World';
        }
    }

    public function run() {
        $nav = '';
        $elements = new CrelishJsonDataProvider('elements', [
            'key' => 'key',
            'sort' => ['by' => ['label', 'asc']],
            'limit' => 99
        ]);

        $params[0] = $this->action;

        foreach (\Yii::$app->getRequest()
                     ->getQueryParams() as $param => $value) {
            $params[$param] = $value;
        }

        foreach ($elements->all()['models'] as $element) {
            if (array_key_exists('selectable', $element) && $element['selectable'] === FALSE) {
                continue;
            }
            $css = ($this->ctype == $element['key']) ? 'gc-active-filter' : '';
            $params[$this->selector] = $element['key'];
            $targetUrl = Url::to($params);
            $nav .= Html::tag('li', Html::a($element['label'], $targetUrl), ['class' => $css]);
        }

        return $nav;
    }
}
