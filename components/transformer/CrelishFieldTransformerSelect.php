<?php

namespace giantbits\crelish\components\transformer;

use giantbits\crelish\components\transformer\CrelishFieldBaseTransformer;

class CrelishFieldTransformerSelect extends CrelishFieldBaseTransformer
{

  public static function transform($fieldConfig, &$value)
  {

    if(!empty($fieldConfig->items)) {
      $items = (array) $fieldConfig->items;
      $value = $items[$value];
    }
  }
}
