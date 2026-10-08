<?php

namespace giantbits\crelish\components\Analytics;

use yii\db\Connection;

/**
 * Writes analytics_visits_daily: distinct sessions per day for the whole site
 * (owner '') and per owner, each for any event ('') and per event type. An owner
 * is the element views' page_uuid or the company owning the element (via
 * ElementOwnership); a session counts once per owner.
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

    /** @var array<string, string> element type => table whose `company` column owns its rows */
    private array $ownedTables;

    /**
     * @param array<string, string>|null $ownedTables Element type => table with a `company` column;
     *        null reads them from the project config via ElementOwnership
     */
    public function __construct(private Connection $db, ?array $ownedTables = null)
    {
        $this->ownedTables = $ownedTables ?? ElementOwnership::companyOwnedTables($db);
    }

    public static function tableExists(Connection $db): bool
    {
        return $db->getTableSchema(self::TABLE, true) !== null;
    }

    /**
     * Compute one day's visit rows.
     *
     * Normal mode replaces the day's rows (DELETE + INSERT in one transaction),
     * but only while the day still has reportable raw data: a day whose raw data
     * is gone (deleted by the cleanup) keeps its stored rows and nothing is written.
     * Repair mode keeps every existing row and merges with GREATEST().
     *
     * @return int Rows written
     */
    public function aggregate(string $date, bool $repair = false): int
    {
        [$start, $end] = AnalyticsRetention::dayRange($date);
        // The day dayRange() normalised to, so an invalid date cannot be stored as written
        $params = [':date' => substr($start, 0, 10), ':start' => $start, ':end' => $end];
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
        ];

        $transaction = $this->db->beginTransaction();
        try {
            if (!$repair) {
                if (!$this->hasRawData($start, $end)) {
                    $transaction->commit();
                    return 0;
                }
                $this->db->createCommand()->delete(self::TABLE, ['date' => $params[':date']])->execute();
            }

            $written = 0;
            foreach ($statements as $select) {
                $written += $this->db->createCommand($insert . $select . $merge, $params)->execute();
            }

            $written += $this->aggregateOwners($params, $insert, $merge);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $written;
    }

    /**
     * Whether the day still has raw rows: a page view of a visitor or suspected
     * bot (is_bot 0/2), or an element view whatever its session. Bot page views
     * (1) can be left behind by a cleanup and do not count. Without raw rows the
     * raw data is gone and the stored rows are all that is left, so a normal
     * run keeps them. With suspected rows only, the day's visits really are zero
     * and the stale rows are replaced (as the page and element aggregates do).
     */
    private function hasRawData(string $start, string $end): bool
    {
        $range = [':start' => $start, ':end' => $end];

        return $this->db->createCommand("SELECT 1 FROM {{%analytics_page_views}}
                WHERE created_at >= :start AND created_at < :end AND is_bot IN (0, 2) LIMIT 1", $range)->queryScalar() !== false
            || $this->db->createCommand("SELECT 1 FROM {{%analytics_element_views}}
                WHERE created_at >= :start AND created_at < :end LIMIT 1", $range)->queryScalar() !== false;
    }

    /**
     * Owner rows: a session counts for an owner when it saw an element whose
     * page_uuid is the owner, or an element the owner owns ($ownedTables).
     *
     * The day's non-bot element views are first copied into a temporary table
     * by their created_at range (an index range scan), so the ownership join,
     * which no index can serve, runs against that copy instead of scanning
     * analytics_element_views and holding locks that block live tracking.
     * The candidates are then collected in a second temporary table, so a
     * session matching an owner both ways counts once and each statement binds
     * only its own placeholders. Both temporary tables are utf8mb4_unicode_ci and
     * the owned tables' uuid is converted to it: project tables mix
     * utf8mb3/utf8mb4 and general/unicode collations.
     */
    private function aggregateOwners(array $params, string $insert, string $merge): int
    {
        $views = 'tmp_visit_element_views';
        $tmp = 'tmp_visit_owners';
        $range = [':start' => $params[':start'], ':end' => $params[':end']];

        $this->dropTemporary($views, $tmp);

        try {
            $this->db->createCommand("CREATE TEMPORARY TABLE {$views} (
                    session_id varchar(100) NOT NULL,
                    user_id int NULL,
                    type varchar(255) NULL,
                    page_uuid varchar(36) NULL,
                    element_type varchar(50) NULL,
                    element_uuid varchar(36) NULL
                ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")->execute();
            $this->db->createCommand("CREATE TEMPORARY TABLE {$tmp} (
                    session_id varchar(100) NOT NULL,
                    user_id int NULL,
                    type varchar(255) NULL,
                    owner varchar(36) NOT NULL
                ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")->execute();

            $this->db->createCommand("INSERT INTO {$views} (session_id, user_id, type, page_uuid, element_type, element_uuid)
                SELECT ev.session_id, ev.user_id, ev.type, ev.page_uuid, ev.element_type, ev.element_uuid
                FROM {{%analytics_element_views}} ev
                INNER JOIN {{%analytics_sessions}} s ON ev.session_id = s.session_id
                WHERE ev.created_at >= :start AND ev.created_at < :end AND s.is_bot = 0", $range)->execute();

            $this->db->createCommand("INSERT INTO {$tmp} (session_id, user_id, type, owner)
                SELECT session_id, user_id, type, page_uuid FROM {$views} WHERE page_uuid <> ''")->execute();

            foreach ($this->ownedTables as $type => $table) {
                $this->db->createCommand("INSERT INTO {$tmp} (session_id, user_id, type, owner)
                    SELECT t.session_id, t.user_id, t.type, x.company
                    FROM {$views} t
                    INNER JOIN " . $this->db->quoteTableName($table) . " x
                        ON CONVERT(x.uuid USING utf8mb4) COLLATE utf8mb4_unicode_ci = t.element_uuid
                    WHERE t.element_type = :type AND x.company IS NOT NULL AND x.company <> ''",
                    [':type' => $type])->execute();
            }

            $users = 'COUNT(DISTINCT CASE WHEN user_id IS NOT NULL AND user_id > 0 THEN user_id END)';
            $date = [':date' => $params[':date']];
            $written = $this->db->createCommand($insert
                . "SELECT :date, 'elements', owner, '', COUNT(DISTINCT session_id), {$users}
                   FROM {$tmp} GROUP BY owner" . $merge, $date)->execute();
            $written += $this->db->createCommand($insert
                . "SELECT :date, 'elements', owner, type, COUNT(DISTINCT session_id), {$users}
                   FROM {$tmp} WHERE type IS NOT NULL AND type <> '' GROUP BY owner, type" . $merge, $date)->execute();

            return $written;
        } finally {
            $this->dropTemporary($views, $tmp);
        }
    }

    private function dropTemporary(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->db->createCommand("DROP TEMPORARY TABLE IF EXISTS {$table}")->execute();
        }
    }
}
