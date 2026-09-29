<?php
namespace giantbits\crelish\migrations;

use yii\db\Migration;

/**
 * Class m260929_120000_create_menu_tables
 *
 * Tables behind the built-in menus: one row per menu, items as an
 * adjacency list. Item labels are translated through the generic
 * `translation` table, which is created here when a project does not have
 * it yet.
 */
class m260929_120000_create_menu_tables extends Migration
{
  public function safeUp()
  {
    $tableOptions = $this->db->driverName === 'mysql'
      ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB'
      : null;

    if ($this->db->getTableSchema('translation', true) === null) {
      $this->createTable('translation', [
        'uuid' => $this->string(36)->notNull()->append('PRIMARY KEY'),
        'language' => $this->string(5)->notNull(),
        'source_model' => $this->string(128)->notNull(),
        'source_model_uuid' => $this->string(36)->notNull(),
        'source_model_attribute' => $this->string(128)->notNull(),
        'translation' => $this->text()->null(),
      ], $tableOptions);

      $this->createIndex('idx-translation-source', 'translation', ['source_model', 'source_model_uuid', 'language']);
    }

    $this->createTable('menu', [
      'uuid' => $this->string(36)->notNull()->append('PRIMARY KEY'),
      'key' => $this->string(64)->notNull(),
      'systitle' => $this->string(128)->notNull(),
      'max_depth' => $this->smallInteger()->notNull()->defaultValue(2),
      'state' => $this->smallInteger()->notNull()->defaultValue(2),
      'created' => $this->integer()->null(),
      'updated' => $this->integer()->null(),
      'created_by' => $this->string(36)->null(),
      'updated_by' => $this->string(36)->null(),
    ], $tableOptions);

    $this->createIndex('idx-menu-key', 'menu', 'key', true);

    $this->createTable('menu_item', [
      'uuid' => $this->string(36)->notNull()->append('PRIMARY KEY'),
      'menu_uuid' => $this->string(36)->notNull(),
      'parent_uuid' => $this->string(36)->null(),
      'sort' => $this->integer()->notNull()->defaultValue(0),
      'label' => $this->string(255)->null(),
      'target_type' => $this->string(16)->notNull(),
      'target_ctype' => $this->string(64)->null(),
      'target_uuid' => $this->string(36)->null(),
      'target_url' => $this->text()->null(),
      'new_window' => $this->boolean()->notNull()->defaultValue(false),
      'state' => $this->smallInteger()->notNull()->defaultValue(2),
      'created' => $this->integer()->null(),
      'updated' => $this->integer()->null(),
      'created_by' => $this->string(36)->null(),
      'updated_by' => $this->string(36)->null(),
      // Inline so SQLite (tests) accepts them; MySQL enforces the cascades
      'FOREIGN KEY ([[menu_uuid]]) REFERENCES [[menu]] ([[uuid]]) ON DELETE CASCADE',
      'FOREIGN KEY ([[parent_uuid]]) REFERENCES [[menu_item]] ([[uuid]]) ON DELETE CASCADE',
    ], $tableOptions);

    $this->createIndex('idx-menu_item-tree', 'menu_item', ['menu_uuid', 'parent_uuid', 'sort']);
    $this->createIndex('idx-menu_item-target', 'menu_item', ['target_ctype', 'target_uuid']);
  }

  public function safeDown()
  {
    $this->dropTable('menu_item');
    $this->dropTable('menu');
    // `translation` is shared with other features and stays
  }
}
