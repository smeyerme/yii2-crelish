<?php
namespace giantbits\crelish\components\transformer;
use yii;

/**
 *
 */
class CrelishFieldBaseTransformer extends yii\base\Component {

	public function init() {

		  parent::init();
	}

	/**
	 * [transform description]
	 * @param  [type] $value [description]
	 * @return [type]        [description]
	 */

	public static function afterSave(&$value) {
		$value = $value;
	}

	/**
	 * [beforeSave description]
	 * @param  [type] $value [description]
	 * @return [type]        [description]
	 */
	public static function beforeSave(&$value) {
		$value = $value;
	}

	/**
	 * [beforeFind description]
	 * @param  [type] $value [description]
	 * @return [type]        [description]
	 */
	public static function beforeFind(&$value) {
		$value = $value;
	}

	/**
	 * [afterFind description]
	 * @param  [type] $value [description]
	 * @return [type]        [description]
	 */
	public static function afterFind(&$value) {
		$value = $value;
	}

}
