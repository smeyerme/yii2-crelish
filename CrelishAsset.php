<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace giantbits\crelish;

use yii\web\AssetBundle;

/**
 * Debugger asset bundle
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class CrelishAsset extends AssetBundle
{
  public $sourcePath = '@app/vendor/giantbits/yii2-crelish/assets';
  public $css = [];
  public $js = [
    'https://cdn.jsdelivr.net/dropzone/4.3.0/dropzone.min.js',
    ['https://kit.fontawesome.com/c7033483c3.js', 'crossorigin' => 'anonymous'],
  ];
  public $jsOptions = ['position' => \yii\web\View::POS_HEAD];

  public $depends = [
    'yii\web\YiiAsset',
    'yii\bootstrap5\BootstrapPluginAsset'
  ];
}
