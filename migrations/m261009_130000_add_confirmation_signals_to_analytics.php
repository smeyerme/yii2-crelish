<?php
namespace giantbits\crelish\migrations;

use yii\db\Migration;

/**
 * Class m261009_130000_add_confirmation_signals_to_analytics
 *
 * Browser confirmation (components/Analytics/BrowserConfirmation): what the
 * browser reported about itself (confirmed_flags, a bitmask of the FLAG_*
 * constants) and the time of the visitor's first interaction with the page
 * (engaged_at), on the page view and on its session.
 *
 * Safe to run again; it does nothing where the analytics tables do not exist.
 */
class m261009_130000_add_confirmation_signals_to_analytics extends Migration
{
  public function safeUp()
  {
    foreach (['{{%analytics_page_views}}', '{{%analytics_sessions}}'] as $name) {
      $table = $this->db->getTableSchema($name, true);
      if ($table === null) {
        continue;
      }

      if (!isset($table->columns['confirmed_flags'])) {
        $this->addColumn($name, 'confirmed_flags', $this->tinyInteger(3)->unsigned()->null());
      }

      if (!isset($table->columns['engaged_at'])) {
        $this->addColumn($name, 'engaged_at', $this->timestamp()->null()->defaultValue(null));
      }
    }
  }

  public function safeDown()
  {
    // Recorded signals would be lost; the columns stay.
    return true;
  }
}
