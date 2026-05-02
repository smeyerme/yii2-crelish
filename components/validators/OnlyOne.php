<?php

namespace giantbits\crelish\components\validators;

use Yii;
use giantbits\crelish\components\CrelishDataProvider;

/**
 * Validator ensuring a CrelishDynamicModel record is unique within its ctype,
 * optionally across a composite of attributes.
 *
 * Usage in an element JSON definition:
 *   "rules": [
 *     ["required"],
 *     ["string", {"max": 36}],
 *     ["giantbits\\\\crelish\\\\components\\\\validators\\\\OnlyOne", {
 *       "targetAttribute": ["event", "sponsor"],
 *       "message": "Dieser Sponsor ist bereits diesem Event zugeordnet."
 *     }]
 *   ]
 *
 * Behavior:
 *   - `targetAttribute` may be a string (single field) or an array of strings
 *     (composite). Defaults to the attribute being validated.
 *   - Skips when any target value is empty (consistent with Yii's `unique`
 *     validator — `required` is the rule for "field must be set").
 *   - Self-excludes when editing an existing record (the model's uuid is
 *     skipped in the duplicate scan), so saving an unchanged update does
 *     not falsely flag the record as a duplicate of itself.
 */
class OnlyOne extends \yii\validators\Validator
{
  /**
   * @var string|string[]|null Attribute(s) composing the uniqueness check.
   * If null, defaults to the attribute being validated.
   */
  public $targetAttribute;

  public $skipOnEmpty = true;

  public function init(): void
  {
    parent::init();
    if ($this->message === null) {
      $this->message = Yii::t('crelish', '{attribute} already exists with this value.');
    }
  }

  public function validateAttribute($model, $attribute): void
  {
    $targets = $this->targetAttribute === null
      ? [$attribute]
      : (array)$this->targetAttribute;

    // Build the filter from the model's current values for each target.
    $filter = [];
    foreach ($targets as $t) {
      $value = $model->{$t} ?? null;

      // Skip the whole check if any target is empty — matches Yii's
      // `unique` semantics with skipOnEmpty. The `required` rule is the
      // right tool for "field must be set".
      if ($this->skipOnEmpty && ($value === null || $value === '')) {
        return;
      }
      $filter[$t] = $value;
    }

    $dataProvider = new CrelishDataProvider($model->ctype, ['filter' => $filter]);
    $rows = $dataProvider->rawAll();

    // Self-exclude: skip the record being edited (its uuid matches one
    // of the rows) so updating an existing record does not flag it as
    // a duplicate of itself.
    $selfUuid = $model->uuid ?? null;
    foreach ($rows as $row) {
      $rowUuid = is_array($row) ? ($row['uuid'] ?? null) : ($row->uuid ?? null);
      if ($selfUuid !== null && $selfUuid !== '' && $rowUuid === $selfUuid) {
        continue;
      }
      $this->addError($model, $attribute, $this->message);
      return;
    }
  }
}
