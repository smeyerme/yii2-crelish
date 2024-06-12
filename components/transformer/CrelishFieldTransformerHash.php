<?php
namespace giantbits\crelish\components\transformer;

use giantbits\crelish\components\transformer\CrelishFieldBaseTransformer;

/**
 *
 */
class CrelishFieldTransformerHash extends CrelishFieldBaseTransformer {

	/**
	 * [transform description]
	 * @param  [type] $value [description]
	 * @return [type]        [description]
	 */
	 public static function beforeSave(&$value) {
 		$value = \Yii::$app->getSecurity()->generatePasswordHash($value);
 	}
}
