<?php

namespace giantbits\crelish\components\transformer;

use giantbits\crelish\components\transformer\CrelishFieldBaseTransformer;

class CrelishFieldTransformerState extends CrelishFieldBaseTransformer
{

  public static function afterFinder(&$value)
  {
	  $value = match ((int)$value) {
		  3 => 'Archived',
		  2 => 'Online',
		  1 => 'Draft',
		  default => 'Offline',
	  };
  }
}
