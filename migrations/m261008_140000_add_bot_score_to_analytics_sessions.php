<?php
namespace giantbits\crelish\migrations;

use yii\db\Migration;

/**
 * Class m261008_140000_add_bot_score_to_analytics_sessions
 *
 * The bot detection keeps its score and its reasons on analytics_sessions. No
 * migration ever created the two columns: installations that have them got
 * them by hand, and on one without them the nightly bot detection fails on its
 * first query, so no session is scored and no bot session is ever deleted.
 *
 * Safe to run again and on an installation that already has the columns or the
 * index; it does nothing where the analytics tables do not exist.
 */
class m261008_140000_add_bot_score_to_analytics_sessions extends Migration
{
  public function safeUp()
  {
    $table = $this->db->getTableSchema('{{%analytics_sessions}}', true);
    if ($table === null) {
      return;
    }

    if (!isset($table->columns['bot_score'])) {
      $this->addColumn('{{%analytics_sessions}}', 'bot_score', $this->tinyInteger(3)->unsigned()->null()->after('is_bot'));
    }

    if (!isset($table->columns['bot_reason'])) {
      $this->addColumn('{{%analytics_sessions}}', 'bot_reason', $this->string(255)->null()->after('bot_score'));
    }

    // The scoring queries select sessions by score range
    $indexed = $this->db->createCommand(
      "SHOW INDEX FROM {{%analytics_sessions}} WHERE Column_name = 'bot_score' AND Seq_in_index = 1"
    )->queryOne();
    if ($indexed === false) {
      $this->createIndex('idx_bot_score', '{{%analytics_sessions}}', 'bot_score');
    }
  }

  public function safeDown()
  {
    // Scores of existing installations would be lost; the columns stay.
    return true;
  }
}
