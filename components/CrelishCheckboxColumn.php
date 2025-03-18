<?php
/**
 * Created by PhpStorm.
 * User: myrst
 * Date: 16.10.2018
 * Time: 00:14
 */

namespace giantbits\crelish\components;


use yii\grid\CheckboxColumn;
use yii\helpers\Html;
use yii\helpers\Json;

class CrelishCheckboxColumn extends CheckboxColumn
{

  protected function renderHeaderCellContent()
  {
    if ($this->header !== null || !$this->multiple) {
      return parent::renderHeaderCellContent();
    }

    return'<label style="padding-left: 0.15rem;">' .
	    Html::checkbox($this->getHeaderCheckBoxName(), false, ['class' => 'select-on-check-all']) . '</label>';
  }

  protected function renderDataCellContent($model, $key, $index)
  {
		
    if ($this->checkboxOptions instanceof Closure) {
      $options = call_user_func($this->checkboxOptions, $model, $key, $index, $this);
    } else {
      $options = $this->checkboxOptions;
    }

    if (!isset($options['value'])) {
      $options['value'] = is_array($key) ? $key['uuid'] : $key;
    }

    if ($this->cssClass !== null) {
      Html::addCssClass($options, $this->cssClass);
    }

    return '<label>' . Html::checkbox($this->name, !empty($options['checked']), $options) . '</label>';
  }
}
