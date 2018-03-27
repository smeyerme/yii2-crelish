<?php

namespace giantbits\crelish\plugins\datalist;

use yii\base\Widget;
use yii\helpers\Json;

class DataList extends Widget
{
  public $data;
  public $formKey;
  public $field;

  public function init()
  {
    parent::init();

    if (!empty($this->data)) {
      $this->data = Json::encode($this->data);
    } else {
      $this->data = Json::encode(['main' => []]);
    }
  }

  public function run()
  {

    return $this->render('datalist.twig', [
      'formKey' => $this->formKey,
      'field' => $this->field,
      'rawData' => $this->data
    ]);
  }
}
