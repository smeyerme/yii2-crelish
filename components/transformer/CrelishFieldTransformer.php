<?php
namespace giantbits\crelish\components\transformer;

interface CrelishFieldTransformer {
	public static function transform(&$value);
}