<?php
namespace giantbits\crelish\migrations;

use yii\db\Migration;

/**
 * Class m260924_120000_create_shortlink_table
 *
 * Table behind the built-in short link feature (campaign and print links).
 * Uses the standard crelish columns (uuid, created, updated, created_by,
 * updated_by, state, systitle) so generic tooling keeps working.
 */
class m260924_120000_create_shortlink_table extends Migration
{
  public function safeUp()
  {
    $tableOptions = $this->db->driverName === 'mysql'
      ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
      : null;

    $this->createTable('{{%shortlink}}', [
      'uuid' => $this->string(36)->notNull()->append('PRIMARY KEY'),
      'created' => $this->integer()->null(),
      'updated' => $this->integer()->null(),
      'created_by' => $this->string(36)->null(),
      'updated_by' => $this->string(36)->null(),
      'state' => $this->smallInteger()->notNull()->defaultValue(1),
      'systitle' => $this->string(255)->notNull(),
      'code' => $this->string(64)->notNull(),
      'target_type' => $this->string(16)->notNull(),
      'target_url' => $this->text()->null(),
      'target_ctype' => $this->string(64)->null(),
      'target_uuid' => $this->string(36)->null(),
      'target_language' => $this->string(8)->null(),
      'fallback_url' => $this->text()->null(),
      'valid_from' => $this->integer()->null(),
      'valid_until' => $this->integer()->null(),
      'note' => $this->text()->null(),
      'logo_asset_uuid' => $this->string(36)->null(),
      'qr_size_mm' => $this->smallInteger()->notNull()->defaultValue(30),
      'qr_color' => $this->string(7)->notNull()->defaultValue('#000000'),
      'qr_quiet_zone' => $this->smallInteger()->notNull()->defaultValue(4),
    ], $tableOptions);

    $this->createIndex('idx-shortlink-code', '{{%shortlink}}', 'code', true);
    $this->createIndex('idx-shortlink-state', '{{%shortlink}}', 'state');
    $this->createIndex('idx-shortlink-target', '{{%shortlink}}', ['target_ctype', 'target_uuid']);
  }

  public function safeDown()
  {
    $this->dropTable('{{%shortlink}}');
  }
}
