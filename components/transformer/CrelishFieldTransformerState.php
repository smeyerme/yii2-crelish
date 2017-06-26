<?php
namespace giantbits\crelish\components\transformer;
use giantbits\crelish\components\transformer\CrelishFieldBaseTransformer;

/**
 *
 */
class CrelishFieldTransformerState extends CrelishFieldBaseTransformer {

	public static function afterFind(&$value) {
	    //{"0":"Offline", "1":"Draft", "2":"Online", "3":"Archived"}

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
