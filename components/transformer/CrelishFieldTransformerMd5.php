<?php
namespace giantbits\crelish\components\transformer;
use giantbits\crelish\components\transformer\CrelishFieldBaseTransformer;

/**
 *
 */
class CrelishFieldTransformerMd5 extends CrelishFieldBaseTransformer {

	/**
	 * [transform description]
	 * @param  [type] $value [description]
	 * @return [type]        [description]
	 */
	 public static function beforeSave(&$value) {
 		$value = md5($value);
 	}

	public static function beforeFind(&$value) {
		$value = md5($value);
	}
}
