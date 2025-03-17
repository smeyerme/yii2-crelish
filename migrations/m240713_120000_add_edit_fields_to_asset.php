<?php

use yii\db\Migration;

/**
 * Class m240713_120000_add_edit_fields_to_asset
 * 
 * Adds fields to the asset table to support edited versions of images
 */
class m240713_120000_add_edit_fields_to_asset extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%asset}}', 'parent_uuid', $this->string(36)->null()->comment('UUID of the original asset'));
        $this->addColumn('{{%asset}}', 'edit_params', $this->text()->null()->comment('JSON encoded edit parameters'));
        $this->addColumn('{{%asset}}', 'edit_type', $this->string(50)->null()->comment('Type of edit (crop, rotate, etc.)'));
        $this->addColumn('{{%asset}}', 'is_original', $this->boolean()->defaultValue(true)->comment('Whether this is an original asset'));
        
        // Add index for faster lookups
        $this->createIndex('idx-asset-parent_uuid', '{{%asset}}', 'parent_uuid');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropIndex('idx-asset-parent_uuid', '{{%asset}}');
        $this->dropColumn('{{%asset}}', 'is_original');
        $this->dropColumn('{{%asset}}', 'edit_type');
        $this->dropColumn('{{%asset}}', 'edit_params');
        $this->dropColumn('{{%asset}}', 'parent_uuid');
    }
} 