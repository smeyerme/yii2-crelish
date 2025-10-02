<?php

namespace giantbits\crelish\assets;

use yii\web\AssetBundle;

/**
 * Click Tracking Asset Bundle
 *
 * Provides reliable click tracking using Beacon API
 */
class ClickTrackingAsset extends AssetBundle
{
    public $sourcePath = '@vendor/giantbits/yii2-crelish/assets';

    public $js = [
        'js/click-tracking.js',
    ];

    public $depends = [];
}