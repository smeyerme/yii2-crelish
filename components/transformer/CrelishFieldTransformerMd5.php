<?php
namespace giantbits\crelish\components\transformer;

class CrelishFieldTransformerMd5 implements CrelishFieldTransformer {
	public static function transform(&$value) {
		$value = md5($value);
	}
}