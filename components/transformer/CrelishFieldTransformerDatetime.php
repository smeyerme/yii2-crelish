<?php
namespace giantbits\crelish\components\transformer;

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
 		$value = (string) strtotime($value);
 	}
}
