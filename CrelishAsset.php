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
  public $css = [
    //'css/all.min.css',
    'css/crelish-modern.css',
    'css/fontawesome.min.css',
    'css/sharp-regular.min.css',
    'css/svg-with-js.min.css',
  ];
  public $js = [
    'https://cdn.jsdelivr.net/dropzone/4.3.0/dropzone.min.js',
  ];
  public $jsOptions = ['position' => \yii\web\View::POS_HEAD];

  public $depends = [
    'yii\web\YiiAsset',
    'yii\bootstrap5\BootstrapPluginAsset'
  ];
}
