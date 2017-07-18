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
        'https://cdn.jsdelivr.net/bootstrap/3.3.7/css/bootstrap.min.css',
        'https://cdn.jsdelivr.net/fontawesome/4.7.0/css/font-awesome.min.css',
        'https://cdn.jsdelivr.net/perfect-scrollbar/0.6.11/css/perfect-scrollbar.min.css',
        'https://cdn.jsdelivr.net/blazecss/3.2.2/blaze.min.css',
        'https://cdn.jsdelivr.net/dropzone/4.3.0/basic.min.css',
        'css/crelish.css',
    ];
    public $js = [
        '//cdn.jsdelivr.net/bootstrap/3.3.7/js/bootstrap.min.js',
        '//cdn.jsdelivr.net/perfect-scrollbar/0.6.11/js/perfect-scrollbar.jquery.min.js',
        '//cdn.jsdelivr.net/sortable/1.5.1/Sortable.min.js',
        '//cdn.jsdelivr.net/riot/3.4.4/riot+compiler.min.js',
        '//cdn.jsdelivr.net/dropzone/4.3.0/dropzone.min.js',
        'js/pace.min.js',
        'js/crelish.js'
    ];
    public $jsOptions = ['position' => \yii\web\View::POS_HEAD];

    public $depends = [
        'yii\web\YiiAsset',
        //'yii\bootstrap\BootstrapAsset'
    ];
}
