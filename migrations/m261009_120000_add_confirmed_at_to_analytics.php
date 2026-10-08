<?php
namespace giantbits\crelish\migrations;

use yii\db\Migration;

/**
 * Class m261009_120000_add_confirmed_at_to_analytics
 *
 * Browser confirmation (components/Analytics/BrowserConfirmation): the time a
 * visitor's browser reported a page view back, on the page view and, for the
 * first one, on its session. Empty for everything recorded before and for
 * every client that never runs the page's script.
 *
 * Safe to run again; it does nothing where the analytics tables do not exist.
 */
class m261009_120000_add_confirmed_at_to_analytics extends Migration
{
  public function safeUp()
  {
    foreach (['{{%analytics_page_views}}', '{{%analytics_sessions}}'] as $name) {
      $table = $this->db->getTableSchema($name, true);

      if ($table !== null && !isset($table->columns['confirmed_at'])) {
        $this->addColumn($name, 'confirmed_at', $this->timestamp()->null()->defaultValue(null));
      }
    }
  }

  public function safeDown()
  {
    // Recorded confirmations would be lost; the columns stay.
    return true;
  }
}
