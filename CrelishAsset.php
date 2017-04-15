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
        '//cdn.jsdelivr.net/bootstrap/3.3.7/css/bootstrap.min.css',
        '//maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css',
        '//cdn.jsdelivr.net/perfect-scrollbar/0.6.11/css/perfect-scrollbar.min.css',
        '//unpkg.com/blaze',
        '//cdn.jsdelivr.net/dropzone/4.3.0/basic.min.css',
        'css/crelish.css',
    ];
    public $js = [
        '//cdn.jsdelivr.net/bootstrap/3.3.7/js/bootstrap.min.js',
        '//cdn.jsdelivr.net/perfect-scrollbar/0.6.11/js/perfect-scrollbar.jquery.min.js',
        '//cdn.jsdelivr.net/riot/2.5.0/riot+compiler.min.js',
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
