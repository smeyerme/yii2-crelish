<?php

namespace giantbits\crelish\models;

use giantbits\crelish\components\ContentUrlResolver;
use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\CrelishTranslationBehavior;
use giantbits\crelish\components\menus\MenuService;
use yii\behaviors\AttributeBehavior;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * One entry of a menu. The label is translatable; content targets are resolved at render time.
 *
 * @property string $uuid
 * @property string $menu_uuid
 * @property string|null $parent_uuid
 * @property int $sort
 * @property string|null $label
 * @property string $target_type
 * @property string|null $target_ctype
 * @property string|null $target_uuid
 * @property string|null $target_url
 * @property bool|int $new_window
 * @property int $state
 * @property int|null $created
 * @property int|null $updated
 * @property string|null $created_by
 * @property string|null $updated_by
 */
class MenuItem extends ActiveRecord
{
  public const STATE_OFFLINE = 0;
  public const STATE_ONLINE = 2;

  public const TARGET_CONTENT = ContentUrlResolver::TARGET_CONTENT;
  public const TARGET_URL = ContentUrlResolver::TARGET_URL;
  public const TARGET_NONE = ContentUrlResolver::TARGET_NONE;

  public static function tableName()
  {
    return 'menu_item';
  }

  public static function primaryKey()
  {
    return ['uuid'];
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
      'translation' => [
        'class' => CrelishTranslationBehavior::class,
      ],
    ];
  }

  public function afterSave($insert, $changedAttributes)
  {
    parent::afterSave($insert, $changedAttributes);
    MenuService::invalidateAfterCommit(static::getDb());
  }

  public function afterDelete()
  {
    parent::afterDelete();
    MenuService::invalidateAfterCommit(static::getDb());
  }
}
