<?php

namespace giantbits\crelish\components\transformer;

/**
 *
 */
class CrelishFieldTransformerDatetime extends CrelishFieldBaseTransformer
{

    /**
     * [transform description]
     * @param  [type] $value [description]
     * @return [type]        [description]
     */
    public static function beforeSave(&$value)
    {
        if(!is_null($value) && str_contains($value, ".")) {
            $value = (string) strtotime($value);
        }
    }

    public static function afterFind(&$value)
    {
        if (empty($value)) {
            $value = null;
        }
        parent::afterFind($value);
    }
}
