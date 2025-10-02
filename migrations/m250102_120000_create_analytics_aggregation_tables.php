<?php
namespace giantbits\crelish\migrations;

use yii\db\Migration;

/**
 * Class m250102_120000_create_analytics_aggregation_tables
 *
 * Creates aggregation tables for analytics data to reduce storage requirements
 * while maintaining granular element-level statistics for partners.
 */
class m250102_120000_create_analytics_aggregation_tables extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        // Daily aggregates table (keep for 12 months)
        $this->createTable('{{%analytics_element_daily}}', [
            'id' => $this->primaryKey(),
            'date' => $this->date()->notNull()->comment('Date of aggregated data'),
            'element_uuid' => $this->string(36)->notNull()->comment('Element UUID'),
            'element_type' => $this->string(50)->notNull()->comment('Element type (news, job, company, etc.)'),
            'page_uuid' => $this->string(36)->null()->comment('Page where element was viewed'),
            'event_type' => $this->string(20)->notNull()->comment('Event type: list, detail, click, download'),

            // Metrics
            'total_views' => $this->integer()->defaultValue(0)->comment('Total views/events'),
            'unique_sessions' => $this->integer()->defaultValue(0)->comment('Unique sessions'),
            'unique_users' => $this->integer()->defaultValue(0)->comment('Unique users (0 if not logged in)'),

            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED');

        // Unique constraint to prevent duplicates
        $this->createIndex(
            'idx-element_daily-unique',
            '{{%analytics_element_daily}}',
            ['date', 'element_uuid', 'element_type', 'event_type', 'page_uuid'],
            true
        );

        // Indexes for common queries
        $this->createIndex('idx-element_daily-date', '{{%analytics_element_daily}}', 'date');
        $this->createIndex('idx-element_daily-element', '{{%analytics_element_daily}}', ['element_uuid', 'event_type']);
        $this->createIndex('idx-element_daily-element_type', '{{%analytics_element_daily}}', ['element_type', 'date']);

        // Monthly aggregates table (keep forever - small)
        $this->createTable('{{%analytics_element_monthly}}', [
            'id' => $this->primaryKey(),
            'year' => $this->smallInteger()->notNull()->comment('Year'),
            'month' => $this->tinyInteger()->notNull()->comment('Month (1-12)'),
            'element_uuid' => $this->string(36)->notNull()->comment('Element UUID'),
            'element_type' => $this->string(50)->notNull()->comment('Element type'),
            'event_type' => $this->string(20)->notNull()->comment('Event type: list, detail, click, download'),

            // Metrics
            'total_views' => $this->integer()->defaultValue(0)->comment('Total views/events'),
            'unique_sessions' => $this->integer()->defaultValue(0)->comment('Unique sessions'),
            'unique_users' => $this->integer()->defaultValue(0)->comment('Unique users'),

            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED');

        // Unique constraint
        $this->createIndex(
            'idx-element_monthly-unique',
            '{{%analytics_element_monthly}}',
            ['year', 'month', 'element_uuid', 'element_type', 'event_type'],
            true
        );

        // Indexes for common queries
        $this->createIndex('idx-element_monthly-year_month', '{{%analytics_element_monthly}}', ['year', 'month']);
        $this->createIndex('idx-element_monthly-element', '{{%analytics_element_monthly}}', ['element_uuid', 'event_type']);

        // Partner statistics cache table (for quick lookups)
        $this->createTable('{{%analytics_partner_stats}}', [
            'partner_id' => $this->integer()->notNull()->comment('Partner/Owner ID'),
            'element_uuid' => $this->string(36)->notNull()->comment('Element UUID'),
            'element_type' => $this->string(50)->notNull()->comment('Element type'),
            'event_type' => $this->string(20)->notNull()->comment('Event type'),
            'period_type' => "ENUM('day', 'week', 'month', 'year', 'all_time') NOT NULL",
            'period_start' => $this->date()->notNull()->comment('Period start date'),

            // Metrics
            'total_views' => $this->integer()->defaultValue(0),
            'unique_sessions' => $this->integer()->defaultValue(0),
            'unique_users' => $this->integer()->defaultValue(0),

            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED');

        // Primary key
        $this->addPrimaryKey(
            'pk-partner_stats',
            '{{%analytics_partner_stats}}',
            ['partner_id', 'element_uuid', 'event_type', 'period_type', 'period_start']
        );

        // Index for partner queries
        $this->createIndex(
            'idx-partner_stats-partner_period',
            '{{%analytics_partner_stats}}',
            ['partner_id', 'period_type', 'period_start']
        );

        // Add index to existing analytics_element_views table for efficient aggregation
        // Note: analytics_element_views doesn't have is_bot column, filtering happens via session join
        $this->execute("
            CREATE INDEX IF NOT EXISTS idx_element_views_aggregation
            ON {{%analytics_element_views}} (created_at, element_uuid, element_type, type)
        ");

        // Daily page views aggregates table (keep for 12 months)
        $this->createTable('{{%analytics_page_daily}}', [
            'id' => $this->primaryKey(),
            'date' => $this->date()->notNull()->comment('Date of aggregated data'),
            'page_uuid' => $this->string(36)->notNull()->comment('Page UUID'),
            'page_url' => $this->string(255)->notNull()->comment('Page URL'),

            // Metrics
            'total_views' => $this->integer()->defaultValue(0)->comment('Total page views'),
            'unique_sessions' => $this->integer()->defaultValue(0)->comment('Unique sessions'),
            'unique_users' => $this->integer()->defaultValue(0)->comment('Unique users'),

            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED');

        // Unique constraint to prevent duplicates
        $this->createIndex(
            'idx-page_daily-unique',
            '{{%analytics_page_daily}}',
            ['date', 'page_uuid'],
            true
        );

        // Indexes for common queries
        $this->createIndex('idx-page_daily-date', '{{%analytics_page_daily}}', 'date');
        $this->createIndex('idx-page_daily-page_uuid', '{{%analytics_page_daily}}', 'page_uuid');

        // Monthly page views aggregates table (keep forever - small)
        $this->createTable('{{%analytics_page_monthly}}', [
            'id' => $this->primaryKey(),
            'year' => $this->smallInteger()->notNull()->comment('Year'),
            'month' => $this->tinyInteger()->notNull()->comment('Month (1-12)'),
            'page_uuid' => $this->string(36)->notNull()->comment('Page UUID'),
            'page_url' => $this->string(255)->notNull()->comment('Page URL'),

            // Metrics
            'total_views' => $this->integer()->defaultValue(0)->comment('Total page views'),
            'unique_sessions' => $this->integer()->defaultValue(0)->comment('Unique sessions'),
            'unique_users' => $this->integer()->defaultValue(0)->comment('Unique users'),

            'created_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED');

        // Unique constraint
        $this->createIndex(
            'idx-page_monthly-unique',
            '{{%analytics_page_monthly}}',
            ['year', 'month', 'page_uuid'],
            true
        );

        // Indexes for common queries
        $this->createIndex('idx-page_monthly-year_month', '{{%analytics_page_monthly}}', ['year', 'month']);
        $this->createIndex('idx-page_monthly-page_uuid', '{{%analytics_page_monthly}}', 'page_uuid');

        // Add index to existing analytics_page_views table for efficient aggregation
        $this->execute("
            CREATE INDEX IF NOT EXISTS idx_page_views_aggregation
            ON {{%analytics_page_views}} (created_at, page_uuid, is_bot)
        ");
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->execute("DROP INDEX IF EXISTS idx_page_views_aggregation ON {{%analytics_page_views}}");
        $this->execute("DROP INDEX IF EXISTS idx_element_views_aggregation ON {{%analytics_element_views}}");

        $this->dropTable('{{%analytics_page_monthly}}');
        $this->dropTable('{{%analytics_page_daily}}');
        $this->dropTable('{{%analytics_partner_stats}}');
        $this->dropTable('{{%analytics_element_monthly}}');
        $this->dropTable('{{%analytics_element_daily}}');
    }
}