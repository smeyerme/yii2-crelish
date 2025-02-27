<?php
namespace giantbits\crelish\models;

use giantbits\crelish\components\CrelishBaseHelper;
use yii\behaviors\AttributeBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

class Bulletin extends ActiveRecord
{
  /**
   * {@inheritdoc}
   */
  public static function tableName()
  {
    return '{{%bulletin}}';
  }

  /**
   * {@inheritdoc}
   */
  public static function primaryKey()
  {
    return ['uuid'];
  }

  public function behaviors()
  {
    return [
      [
        'class' => AttributeBehavior::class,
        'attributes' => [
          ActiveRecord::EVENT_BEFORE_INSERT => 'uuid',
        ],
        'value' => function () {
          return CrelishBaseHelper::GUIDv4();
        },
      ],
      'timestamp' => [
        'class' => TimestampBehavior::class,
      ],
    ];
  }

  // Rest of your model code...
}