<?php

namespace giantbits\crelish\models;

use giantbits\crelish\components\CrelishBaseHelper;
use yii\behaviors\AttributeBehavior;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

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
      ['key', 'string', 'max' => 64],
      ['key', 'match', 'pattern' => '/^[a-z0-9_-]+$/'],
      ['key', 'unique'],
      ['systitle', 'string', 'max' => 128],
      ['max_depth', 'default', 'value' => 2],
      ['max_depth', 'integer', 'min' => 1, 'max' => self::MAX_DEPTH_LIMIT],
      ['state', 'default', 'value' => self::STATE_ONLINE],
      ['state', 'in', 'range' => [self::STATE_OFFLINE, self::STATE_ONLINE]],
    ];
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

    return parent::beforeSave($insert);
  }

  public function beforeDelete()
  {
    if (!parent::beforeDelete()) {
      return false;
    }

    // SQLite does not enforce the FK cascade, and translations have no FK at all
    $itemUuids = MenuItem::find()->select('uuid')->where(['menu_uuid' => $this->uuid])->column();

    if ($itemUuids) {
      CrelishTranslation::deleteAll(['source_model' => MenuItem::tableName(), 'source_model_uuid' => $itemUuids]);
      MenuItem::deleteAll(['uuid' => $itemUuids]);
    }

    return true;
  }
}
