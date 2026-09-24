<?php

namespace giantbits\crelish\models;

use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\shortlinks\ShortLinkCode;
use giantbits\crelish\components\shortlinks\ShortLinkConfig;
use Yii;
use yii\behaviors\AttributeBehavior;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A campaign short link: /go/<code> redirects to an external URL or a crelish record.
 *
 * @property string $uuid
 * @property int|null $created
 * @property int|null $updated
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property int $state
 * @property string $systitle
 * @property string $code
 * @property string $target_type
 * @property string|null $target_url
 * @property string|null $target_ctype
 * @property string|null $target_uuid
 * @property string|null $target_language
 * @property string|null $fallback_url
 * @property int|string|null $valid_from
 * @property int|string|null $valid_until
 * @property string|null $note
 * @property string|null $logo_asset_uuid
 * @property int $qr_size_mm
 * @property string $qr_color
 * @property int $qr_quiet_zone
 * @property string $validFromInput
 * @property string $validUntilInput
 */
class ShortLink extends ActiveRecord
{
  public const STATE_OFFLINE = 0;
  public const STATE_DRAFT = 1;
  public const STATE_ONLINE = 2;
  public const STATE_ARCHIVED = 3;

  public const TARGET_URL = 'url';
  public const TARGET_CONTENT = 'content';

  public const STATUS_ONLINE = 'online';
  public const STATUS_OFFLINE = 'offline';
  public const STATUS_DRAFT = 'draft';
  public const STATUS_ARCHIVED = 'archived';
  public const STATUS_SCHEDULED = 'scheduled';
  public const STATUS_EXPIRED = 'expired';

  private const NULLABLE = ['target_url', 'target_ctype', 'target_uuid', 'target_language', 'fallback_url', 'note', 'logo_asset_uuid'];

  public static function tableName()
  {
    return '{{%shortlink}}';
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
      [['systitle', 'code', 'target_type'], 'required'],
      ['code', 'filter', 'filter' => [ShortLinkCode::class, 'normalize']],
      ['code', 'validateCode'],
      ['code', 'unique'],
      ['systitle', 'string', 'max' => 255],
      ['target_type', 'in', 'range' => [self::TARGET_URL, self::TARGET_CONTENT]],
      [['target_url', 'fallback_url'], 'filter', 'filter' => 'trim', 'skipOnEmpty' => true],
      [['target_url', 'fallback_url'], 'url', 'validSchemes' => ['http', 'https']],
      ['target_url', 'required', 'when' => fn(self $model) => $model->target_type === self::TARGET_URL],
      [['target_ctype', 'target_uuid'], 'required', 'when' => fn(self $model) => $model->target_type === self::TARGET_CONTENT],
      ['target_ctype', 'string', 'max' => 64],
      [['target_uuid', 'logo_asset_uuid'], 'string', 'max' => 36],
      ['target_language', 'string', 'max' => 8],
      ['state', 'default', 'value' => self::STATE_DRAFT],
      ['state', 'filter', 'filter' => 'intval'],
      ['state', 'in', 'range' => [self::STATE_OFFLINE, self::STATE_DRAFT, self::STATE_ONLINE, self::STATE_ARCHIVED]],
      [['valid_from', 'valid_until'], 'integer'],
      ['valid_until', 'compare', 'compareAttribute' => 'valid_from', 'operator' => '>', 'type' => 'number',
        'when' => fn(self $model) => $model->valid_from !== null && $model->valid_from !== ''],
      ['note', 'string'],
      ['qr_size_mm', 'default', 'value' => 30],
      ['qr_size_mm', 'integer', 'min' => 10, 'max' => 500],
      ['qr_color', 'default', 'value' => '#000000'],
      ['qr_color', 'match', 'pattern' => '/^#[0-9a-fA-F]{6}$/'],
      ['qr_quiet_zone', 'default', 'value' => 4],
      ['qr_quiet_zone', 'integer', 'min' => 4, 'max' => 20],
      [['validFromInput', 'validUntilInput'], 'safe'],
    ];
  }

  public function validateCode(string $attribute): void
  {
    $error = ShortLinkCode::formatError((string)$this->$attribute);

    if ($error !== null) {
      $this->addError($attribute, Yii::t('crelish', $error));
    }
  }

  public function beforeSave($insert)
  {
    if ($this->target_type === self::TARGET_URL) {
      $this->target_ctype = null;
      $this->target_uuid = null;
      $this->target_language = null;
    } else {
      $this->target_url = null;
    }

    foreach (self::NULLABLE as $attribute) {
      if ($this->$attribute === '') {
        $this->$attribute = null;
      }
    }

    return parent::beforeSave($insert);
  }

  /**
   * Load the admin form: ShortLink[...] plus the asset connector's logo field,
   * which always posts as CrelishDynamicModel[logo_asset_uuid].
   */
  public function loadForm(array $post): bool
  {
    $loaded = $this->load($post);
    $asset = $post['CrelishDynamicModel']['logo_asset_uuid'] ?? null;

    if ($asset !== null) {
      $this->logo_asset_uuid = $asset === '' ? null : (string)$asset;
      $loaded = true;
    }

    return $loaded;
  }

  public function getStatus(?int $now = null): string
  {
    $now ??= time();

    switch ((int)$this->state) {
      case self::STATE_OFFLINE:
        return self::STATUS_OFFLINE;
      case self::STATE_DRAFT:
        return self::STATUS_DRAFT;
      case self::STATE_ARCHIVED:
        return self::STATUS_ARCHIVED;
    }

    if ($this->valid_from !== null && $this->valid_from !== '' && $now < (int)$this->valid_from) {
      return self::STATUS_SCHEDULED;
    }

    if ($this->valid_until !== null && $this->valid_until !== '' && $now > (int)$this->valid_until) {
      return self::STATUS_EXPIRED;
    }

    return self::STATUS_ONLINE;
  }

  public function isLive(?int $now = null): bool
  {
    return $this->getStatus($now) === self::STATUS_ONLINE;
  }

  public function getShortUrl(bool $qr = false): string
  {
    $path = $this->code . ($qr ? '/q' : '');
    $host = ShortLinkConfig::shortHost();

    if ($host !== null) {
      return 'https://' . $host . '/' . $path;
    }

    return ShortLinkConfig::siteUrl() . '/' . ShortLinkConfig::prefix() . '/' . $path;
  }

  /**
   * The QR content: uppercase so the code uses the denser-packing alphanumeric mode
   */
  public function getQrPayload(): string
  {
    return strtoupper($this->getShortUrl(true));
  }

  public function getValidFromInput(): string
  {
    return self::formatInput($this->valid_from);
  }

  public function setValidFromInput($value): void
  {
    $this->valid_from = self::parseInput($value);
  }

  public function getValidUntilInput(): string
  {
    return self::formatInput($this->valid_until);
  }

  public function setValidUntilInput($value): void
  {
    $this->valid_until = self::parseInput($value);
  }

  public static function findByCode(string $code): ?self
  {
    return static::findOne(['code' => ShortLinkCode::normalize($code)]);
  }

  private static function formatInput($timestamp): string
  {
    return $timestamp ? date('Y-m-d\TH:i', (int)$timestamp) : '';
  }

  private static function parseInput($value): ?int
  {
    $value = trim((string)$value);

    if ($value === '') {
      return null;
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? null : $timestamp;
  }
}
