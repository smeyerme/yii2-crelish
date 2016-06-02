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
class CrelishAsset extends AssetBundle {
  public $sourcePath = '@app/vendor/giantbits/yii2-crelish/assets';
  public $css = [
    '//cdn.jsdelivr.net/perfect-scrollbar/0.6.11/css/perfect-scrollbar.min.css',
    '//cdn.jsdelivr.net/blazecss/latest/blaze.min.css',
    '//cdn.jsdelivr.net/dropzone/4.3.0/basic.min.css',
    'css/flat-ui-pro.css',
    'css/crelish.css',
  ];
  public $js = [
    'js/flat-ui-pro.min.js',
    'js/crelish.js',
    '//cdn.jsdelivr.net/perfect-scrollbar/0.6.11/js/perfect-scrollbar.jquery.min.js',
    '//cdn.jsdelivr.net/riot/2.4/riot+compiler.min.js',
    '//cdn.jsdelivr.net/riotgear/latest/rg.min.js',
    '//cdn.giantbits.com/Fgh53Ya0i/riotcontrol.js',
    '//cdn.jsdelivr.net/chart.js/1.0.2/Chart.min.js',
    '//cdn.jsdelivr.net/sortable/latest/Sortable.min.js',
    '//cdn.jsdelivr.net/dropzone/4.3.0/dropzone.min.js'
  ];
  public $jsOptions = ['position' => \yii\web\View::POS_HEAD];
  public $depends = [
    'yii\web\YiiAsset',
    'yii\bootstrap\BootstrapAsset',
  ];
}
