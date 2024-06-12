<?php

namespace giantbits\crelish\components\transformer;

use giantbits\crelish\components\transformer\CrelishFieldBaseTransformer;

class CrelishFieldTransformerState extends CrelishFieldBaseTransformer
{

  public static function afterFinder(&$value)
  {
    switch ($value) {
      case 3:
        $value = 'Archived';
        break;
      case 2:
        $value = 'Online';
        break;
      case 1:
        $value = 'Draft';
        break;
      default:
        $value = 'Offline';
    }
  }
}
