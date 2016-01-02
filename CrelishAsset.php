<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace crelish\crelish;

use yii\web\AssetBundle;

/**
 * Debugger asset bundle
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class CrelishAsset extends AssetBundle {
  public $sourcePath = '@yii/crelish/assets';
  public $css = [
    'main.css',
    'toolbar.css',
  ];
  public $depends = [
    'yii\web\YiiAsset',
    'yii\bootstrap\BootstrapAsset',
  ];
}
