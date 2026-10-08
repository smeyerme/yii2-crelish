<?php
namespace giantbits\crelish\migrations;

use yii\db\Migration;

/**
 * Class m261008_120000_create_analytics_visits_daily
 *
 * Distinct visits (sessions) per day for the whole site and per owner (the
 * page_uuid element views carry; for jobs the owning company), so reports can
 * add up days without adding up the same visitor across pages, elements or
 * event types. Safe to run again: it does nothing when the table exists.
 */
class m261008_120000_create_analytics_visits_daily extends Migration
{
  public function safeUp()
  {
    if ($this->db->getTableSchema('{{%analytics_visits_daily}}', true) !== null) {
      return;
    }

    $tableOptions = $this->db->driverName === 'mysql'
      ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
      : null;

    $this->createTable('{{%analytics_visits_daily}}', [
      'id' => $this->primaryKey(),
      'date' => $this->date()->notNull(),
      'source' => $this->string(16)->notNull(),
      'owner_uuid' => $this->string(36)->notNull()->defaultValue(''),
      'event_type' => $this->string(50)->notNull()->defaultValue(''),
      'unique_sessions' => $this->integer()->notNull()->defaultValue(0),
      'unique_users' => $this->integer()->notNull()->defaultValue(0),
      'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
      'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
    ], $tableOptions);

    $this->createIndex('idx-visits_daily-unique', '{{%analytics_visits_daily}}', ['date', 'source', 'owner_uuid', 'event_type'], true);
    $this->createIndex('idx-visits_daily-owner', '{{%analytics_visits_daily}}', ['owner_uuid', 'date']);
  }

  public function safeDown()
  {
    $this->dropTable('{{%analytics_visits_daily}}');
  }
}
