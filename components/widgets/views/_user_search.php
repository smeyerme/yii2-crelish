<?php
/**
 * @var string $filterValue
 * @var string $statusValue
 */
use yii\helpers\Html;
?>

<button class="c-button c-button--brand"><i class="fa-sharp fa-regular fa-search"></i></button>
<div class="o-field" style="display: flex;">
    <input class="c-field" name="cr_content_filter" id="cr_content_filter"
           value="<?= Html::encode($filterValue) ?>"
           placeholder="<?= Yii::t('app', 'Type your search phrase here...') ?>">
    <select name="cr_status_filter" id="cr_status_filter" style="border: none;">
        <option value=""><?= Yii::t('app', 'State') ?></option>
        <option value="1"<?= $statusValue == 1 ? ' selected' : '' ?>><?= Yii::t('app', 'Inactive') ?></option>
        <option value="2"<?= $statusValue == 2 ? ' selected' : '' ?>><?= Yii::t('app', 'Online') ?></option>
        <option value="3"<?= $statusValue == 3 ? ' selected' : '' ?>><?= Yii::t('app', 'Archived') ?></option>
    </select>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Handle status filter change
        document.getElementById('cr_status_filter').addEventListener('change', function() {
            var url = new URL(window.location.href);
            url.searchParams.set('cr_status_filter', this.value);
            window.location.href = url.toString();
        });
    });
</script> 