<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace giantbits\crelish;

use yii\web\AssetBundle;

/**
 * Crelish Modern UI asset bundle
 */
class CrelishModernAsset extends AssetBundle
{
  public $sourcePath = '@app/vendor/giantbits/yii2-crelish/assets';
  public $css = [
    'css/crelish-modern.css',
  ];
  public $js = [];
  public $jsOptions = ['position' => \yii\web\View::POS_END];

  public $depends = [
    'giantbits\crelish\CrelishAsset'
  ];
} 