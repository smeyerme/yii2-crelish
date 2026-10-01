<?php

namespace giantbits\crelish\models;

use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\menus\MenuService;
use yii\behaviors\AttributeBehavior;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Expression;

/**
 * A named navigation menu (main, footer, meta …) that themes render via chelper.menu('<key>').
 *
 * @property string $uuid
 * @property string $key
 * @property string $systitle
 * @property int $max_depth
 * @property int $state
 * @property int|null $created
 * @property int|null $updated
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property MenuItem[] $items
 */
class Menu extends ActiveRecord
{
  public const STATE_OFFLINE = 0;
  public const STATE_ONLINE = 2;

  public const MAX_DEPTH_LIMIT = 5;

  public static function tableName()
  {
    return 'menu';
  }

  public static function primaryKey()
  {
    return ['uuid'];
  }

  /**
   * The whole delete (items, translations, the menu row) runs in one transaction.
   */
  public function transactions()
  {
    return [self::SCENARIO_DEFAULT => self::OP_DELETE];
  }

  public function behaviors()
  {
    return [
      'uuid' => [
        'class' => AttributeBehavior::class,
        'attributes' => [ActiveRecord::EVENT_BEFORE_INSERT => 'uuid'],
        'value' => fn() => $this->uuid ?: CrelishBaseHelper::GUIDv4(),
      ],
      'timestamp' => [
        'class' => TimestampBehavior::class,
        'createdAtAttribute' => 'created',
        'updatedAtAttribute' => 'updated',
      ],
      'blameable' => [
        'class' => BlameableBehavior::class,
        'createdByAttribute' => 'created_by',
        'updatedByAttribute' => 'updated_by',
      ],
    ];
  }

  public function rules()
  {
    return [
      [['key', 'systitle'], 'required'],
      [['key', 'systitle'], 'trim'],
      // The key is immutable (beforeSave resets it), so only a new menu's key is validated
      ['key', 'string', 'max' => 64, 'when' => fn($m) => $m->isNewRecord],
      ['key', 'match', 'pattern' => '/^[a-z0-9_-]+$/', 'when' => fn($m) => $m->isNewRecord],
      ['key', 'unique', 'when' => fn($m) => $m->isNewRecord],
      ['systitle', 'string', 'max' => 128],
      ['max_depth', 'default', 'value' => 2],
      ['max_depth', 'integer', 'min' => 1, 'max' => self::MAX_DEPTH_LIMIT],
      ['max_depth', 'validateStoredDepth', 'when' => fn() => !$this->isNewRecord],
      ['state', 'default', 'value' => self::STATE_ONLINE],
      ['state', 'in', 'range' => [self::STATE_OFFLINE, self::STATE_ONLINE]],
    ];
  }

  /**
   * Reducing the depth must not strand items that already sit deeper.
   */
  public function validateStoredDepth(string $attribute): void
  {
    if ($this->hasErrors($attribute)) {
      return;
    }

    $depth = $this->storedDepth();

    if ($depth > (int)$this->$attribute) {
      $this->addError($attribute, \Yii::t('crelish', 'This menu already has items {depth} levels deep. Move or remove them before reducing the depth.', ['depth' => $depth]));
    }
  }

  /**
   * Deepest level of the stored items (0 for an empty menu), from the parent chains.
   */
  public function storedDepth(): int
  {
    $parents = MenuItem::find()
      ->select(['parent_uuid', 'uuid'])
      ->where(['menu_uuid' => $this->uuid])
      ->asArray()
      ->all();
    $parentOf = array_column($parents, 'parent_uuid', 'uuid');
    $max = 0;

    foreach (array_keys($parentOf) as $uuid) {
      $depth = 1;
      $seen = [$uuid => true];

      while (($uuid = $parentOf[$uuid] ?? null) !== null && array_key_exists($uuid, $parentOf) && !isset($seen[$uuid])) {
        $seen[$uuid] = true;
        $depth++;
      }

      $max = max($max, $depth);
    }

    return $max;
  }

  public function attributeLabels()
  {
    return [
      'key' => \Yii::t('crelish', 'Key'),
      'systitle' => \Yii::t('crelish', 'Title'),
      'max_depth' => \Yii::t('crelish', 'Maximum depth'),
      'state' => \Yii::t('crelish', 'Status'),
    ];
  }

  public function getItems(): ActiveQuery
  {
    return $this->hasMany(MenuItem::class, ['menu_uuid' => 'uuid'])->orderBy(['sort' => SORT_ASC, 'uuid' => SORT_ASC]);
  }

  public function beforeSave($insert)
  {
    // Themes look menus up by key, so it never changes once created
    if (!$insert && $this->isAttributeChanged('key')) {
      $this->key = $this->getOldAttribute('key');
    }

    if (!parent::beforeSave($insert)) {
      return false;
    }

    // The tree saver may have bumped updated past time(); a plain time() here could move it back and revive an old editor token
    if (!$insert && $this->getDirtyAttributes() !== []) {
      // Computed by the database from the current value: a tree save between findOne() and save() must not be undone
      $function = $this->getDb()->driverName === 'sqlite' ? 'MAX' : 'GREATEST';
      $this->updated = new Expression($function . '(COALESCE(updated, 0) + 1, :now)', [':now' => time()]);
    }

    return true;
  }

  /**
   * Next value of the optimistic-concurrency version: never lower than now, always above the old one.
   */
  public static function nextUpdated(int $old): int
  {
    return max(time(), $old + 1);
  }

  public function beforeDelete()
  {
    if (!parent::beforeDelete()) {
      return false;
    }

    // SQLite does not enforce the FK cascade, and translations have no FK at all
    $itemUuids = MenuItem::find()->select('uuid')->where(['menu_uuid' => $this->uuid])->column();

    if (!$itemUuids) {
      return true;
    }

    // delete() already runs inside the AR transaction (transactions() OP_DELETE) and this reuses it;
    // the own transaction is only a fallback for direct calls, so a failure half-way cannot leave translations gone but items behind
    $db = static::getDb();
    $transaction = $db->getTransaction() === null ? $db->beginTransaction() : null;

    try {
      CrelishTranslation::deleteAll(['source_model' => MenuItem::tableName(), 'source_model_uuid' => $itemUuids]);
      MenuItem::deleteAll(['uuid' => $itemUuids]);
      $transaction?->commit();
    } catch (\Throwable $e) {
      $transaction?->rollBack();
      throw $e;
    }

    return true;
  }

  public function afterSave($insert, $changedAttributes)
  {
    parent::afterSave($insert, $changedAttributes);

    // beforeSave left an expression in the attribute; show the value the database computed
    if (!$insert && $this->updated instanceof Expression) {
      $value = (int)static::find()->select('updated')->where(['uuid' => $this->uuid])->scalar();
      $this->updated = $value;
      $this->setOldAttribute('updated', $value);
    }

    MenuService::invalidate();
  }

  public function afterDelete()
  {
    parent::afterDelete();
    MenuService::invalidate();
  }
}
