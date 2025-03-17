<?php
/**
 * @var string $filterValue
 */
use yii\helpers\Html;
?>

<button class="c-button c-button--brand"><i class="fa-sharp fa-regular fa-search"></i></button>
<div class="o-field">
    <input class="c-field" name="cr_content_filter" id="cr_content_filter"
           value="<?= Html::encode($filterValue) ?>"
           placeholder="<?= Yii::t('app', 'Type your search phrase here...') ?>">
</div> 