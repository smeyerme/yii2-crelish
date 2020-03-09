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
    //'https://cdn.jsdelivr.net/dropzone/4.3.0/basic.min.css',
    'https://cdn.jsdelivr.net/npm/perfect-scrollbar@1.4.0/css/perfect-scrollbar.min.css',
    'https://cdn.jsdelivr.net/npm/blaze-css@1.2.2/dist/blaze.min.css',
    ['https://use.fontawesome.com/releases/v5.3.1/css/all.css', 'integrity' => 'sha384-mzrmE5qonljUremFsqc01SB46JvROS7bZs3IO2EmfFsd15uHvIt+Y8vEf7N7fWAU', 'crossorigin' => 'anonymous'],
    'css/crelish.css',
  ];
  public $js = [
    //'https://cdn.jsdelivr.net/bootstrap/3.3.7/js/bootstrap.min.js',
    'https://cdn.jsdelivr.net/npm/@shopify/draggable@1.0.0-beta.7/lib/draggable.bundle.min.js',
    //'js/pace.min.js',
    //'https://cdn.jsdelivr.net/npm/progressbar.js@1.0.1/dist/progressbar.min.js',
    'https://cdn.jsdelivr.net/dropzone/4.3.0/dropzone.min.js',
    'https://cdn.jsdelivr.net/riot/3.4.4/riot+compiler.min.js',
    'https://cdn.jsdelivr.net/npm/perfect-scrollbar@1.4.0/dist/perfect-scrollbar.min.js',
    'https://cdn.jsdelivr.net/npm/split.js@1.5.11/dist/split.min.js',
    'https://cdn.jsdelivr.net/npm/pace-js@1.0.2/pace.min.js',
    'js/crelish.js'
  ];
  public $jsOptions = ['position' => \yii\web\View::POS_HEAD];

  public $depends = [
    'yii\web\YiiAsset',
    'yii\bootstrap\BootstrapPluginAsset'
  ];
}
