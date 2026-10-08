<?php

namespace giantbits\crelish\components\Analytics;

use yii\db\Connection;

/**
 * Writes analytics_visits_daily: distinct sessions per day for the whole site
 * (owner '') and per owner (the element views' page_uuid), each for any event
 * ('') and per event type.
 *
 * Bot filtering matches the daily aggregates: page views by is_bot = 0, element
 * views through a session with is_bot = 0. Element views without a type only
 * count for the any-event rows; views without an owner only for the site rows,
 * so neither collides with the '' rows.
 */
final class VisitsAggregator
{
    public const TABLE = '{{%analytics_visits_daily}}';
    public const SOURCE_PAGES = 'pages';
    public const SOURCE_ELEMENTS = 'elements';

    public function __construct(private Connection $db)
    {
    }

    public static function tableExists(Connection $db): bool
    {
        return $db->getTableSchema(self::TABLE, true) !== null;
    }

    /**
     * Compute one day's visit rows.
     *
     * Normal mode replaces the day's rows (DELETE + INSERT in one transaction).
     * Repair mode keeps every existing row and merges with GREATEST().
     *
     * @return int Rows written
     */
    public function aggregate(string $date, bool $repair = false): int
    {
        $params = [
            ':date' => $date,
            ':start' => $date . ' 00:00:00',
            ':end' => date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00',
        ];
        $merge = $repair
            ? ' ON DUPLICATE KEY UPDATE unique_sessions = GREATEST(unique_sessions, VALUES(unique_sessions)),'
                . ' unique_users = GREATEST(unique_users, VALUES(unique_users)), updated_at = NOW()'
            : '';
        $insert = 'INSERT INTO ' . self::TABLE . ' (date, source, owner_uuid, event_type, unique_sessions, unique_users) ';
        $users = 'COUNT(DISTINCT CASE WHEN %1$s IS NOT NULL AND %1$s > 0 THEN %1$s END)';
        $elementUsers = sprintf($users, 'ev.user_id');
        $elements = "FROM {{%analytics_element_views}} ev
            INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
            WHERE ev.created_at >= :start AND ev.created_at < :end AND s.is_bot = 0";
        $typed = "AND ev.type IS NOT NULL AND ev.type <> ''";
        $owned = "AND ev.page_uuid <> ''";

        $statements = [
            // Site: page visits
            "SELECT :date, 'pages', '', '', COUNT(DISTINCT session_id), " . sprintf($users, 'user_id') . "
             FROM {{%analytics_page_views}}
             WHERE created_at >= :start AND created_at < :end AND is_bot = 0
             HAVING COUNT(*) > 0",
            // Site: element visits, any event
            "SELECT :date, 'elements', '', '', COUNT(DISTINCT ev.session_id), {$elementUsers}
             {$elements} HAVING COUNT(*) > 0",
            // Site: element visits per event type
            "SELECT :date, 'elements', '', ev.type, COUNT(DISTINCT ev.session_id), {$elementUsers}
             {$elements} {$typed} GROUP BY ev.type",
            // Owner: any event
            "SELECT :date, 'elements', ev.page_uuid, '', COUNT(DISTINCT ev.session_id), {$elementUsers}
             {$elements} {$owned} GROUP BY ev.page_uuid",
            // Owner: per event type
            "SELECT :date, 'elements', ev.page_uuid, ev.type, COUNT(DISTINCT ev.session_id), {$elementUsers}
             {$elements} {$owned} {$typed} GROUP BY ev.page_uuid, ev.type",
        ];

        $transaction = $this->db->beginTransaction();
        try {
            if (!$repair) {
                $this->db->createCommand()->delete(self::TABLE, ['date' => $date])->execute();
            }

            $written = 0;
            foreach ($statements as $select) {
                $written += $this->db->createCommand($insert . $select . $merge, $params)->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $written;
    }
}
