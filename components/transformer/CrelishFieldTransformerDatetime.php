<?php
namespace giantbits\crelish\components\transformer;
use giantbits\crelish\components\transformer\CrelishFieldBaseTransformer;

/**
 *
 */
class CrelishFieldTransformerDatetime extends CrelishFieldBaseTransformer {

	/**
	 * [transform description]
	 * @param  [type] $value [description]
	 * @return [type]        [description]
	 */
	 public static function beforeSave(&$value) {
 		$value = strtotime($value);
 	}

    
    public static function afterFind(&$value) {
        $value = strftime("%d.%m.%Y", $value);
    }
}
