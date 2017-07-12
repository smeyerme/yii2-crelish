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
class CrelishElementsEditAsset extends AssetBundle
{
    public $sourcePath = '@app/vendor/giantbits/yii2-crelish/assets';
    public $css = [
    ];
    public $js = [
        'https://code.jquery.com/ui/1.12.1/jquery-ui.js',
        'js/elementsEdit.js'
    ];
    public $jsOptions = ['position' => \yii\web\View::POS_HEAD];

    public $depends = [
        'yii\web\YiiAsset',
        //'yii\bootstrap\BootstrapAsset'
    ];
}
