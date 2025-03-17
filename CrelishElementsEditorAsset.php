<?php

namespace giantbits\crelish;

use yii\web\AssetBundle;

/**
 * Asset bundle for the Elements Editor Vue app
 */
class CrelishElementsEditorAsset extends AssetBundle
{
    public $sourcePath = '@giantbits/crelish/resources/elements-editor/dist';
    
    public $js = [
        'elements-editor.js',
    ];
    
    public $depends = [
        'yii\web\YiiAsset',
        'yii\bootstrap5\BootstrapAsset',
        'yii\bootstrap5\BootstrapPluginAsset',
    ];
} 