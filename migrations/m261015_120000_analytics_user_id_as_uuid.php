<?php
namespace giantbits\crelish\migrations;

use yii\db\Migration;

/**
 * Class m261015_120000_analytics_user_id_as_uuid
 *
 * Analytics stores the logged-in user as Yii::$app->user->id, which in crelish is a
 * UUID, but analytics_page_views, analytics_sessions and analytics_element_views had
 * user_id as int(11). On a strict MySQL every page view of a logged-in user failed
 * ("Out of range" for UUIDs like 5e27…, "Incorrect integer value" for the rest); on a
 * non-strict one MySQL silently stored 0, the leading digits or 2147483647.
 *
 * The column becomes varchar(36). The stored numbers cannot be traced back to a user,
 * so they are cleared. Safe to run again; skips tables that do not exist or already
 * have a string column.
 */
class m261015_120000_analytics_user_id_as_uuid extends Migration
{
  private const TABLES = ['analytics_page_views', 'analytics_sessions', 'analytics_element_views'];

  public function safeUp()
  {
    foreach (self::TABLES as $name) {
      $table = $this->db->getTableSchema('{{%' . $name . '}}', true);
      $column = $table?->columns['user_id'] ?? null;
      if ($column === null || $column->phpType === 'string') {
        continue;
      }

      $this->update('{{%' . $name . '}}', ['user_id' => null], ['not', ['user_id' => null]]);
      $this->alterColumn('{{%' . $name . '}}', 'user_id', $this->string(36)->null());
    }
  }

  public function safeDown()
  {
    // UUIDs do not fit an int column; the column stays a string.
    return true;
  }
}
