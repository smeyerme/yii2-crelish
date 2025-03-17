<?php
/**
 * @var string $leftComponents
 * @var string $rightComponents
 * @var array $options
 */
use yii\helpers\Html;
?>

<nav <?= Html::renderTagAttributes($options) ?>>
    <div class="c-input-group group-content-filter">
        <?= $leftComponents ?>
    </div>
    <div class="c-input-group">
        <?= $rightComponents ?>
    </div>
</nav> 