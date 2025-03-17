<?php
use yii\helpers\Html;
?>

<div class="c-input-group">
    <span id="submitButton" class="c-button btn-save c-button--success" type="button"
          onclick="$('#content-form').submit();">
        <i class="fa-sharp fa-regular fa-save"></i>
    </span>
    <span class="c-button btn-save c-button--success-darker" type="button"
          onclick="$('#save_n_return').val(1); $('#content-form').submit();">
        <i class="fa-sharp fa-regular fa-save"></i> <i class="fa-sharp fa-regular fa-reply"></i>
    </span>
</div>

<script>
  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key === 's') {
      event.preventDefault();
      document.getElementById('submitButton').click();
    }
  });
</script> 