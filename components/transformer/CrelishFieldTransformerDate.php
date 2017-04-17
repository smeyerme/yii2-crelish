<?php
namespace giantbits\crelish\components\transformer;

/**
 *
 */
class CrelishFieldTransformerDate extends CrelishFieldBaseTransformer {

	/**
	 * [transform description]
	 * @param  [type] $value [description]
	 * @return [type]        [description]
	 */
	 public static function beforeSave(&$value) {
 		$value = (string) strtotime($value);
 	}

}
