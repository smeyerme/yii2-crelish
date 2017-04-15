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
    
    public static function afterFind(&$value) {
        $value = (!empty($value)) ? strftime("%d.%m.%Y", (int) $value) : '';
    }
}
