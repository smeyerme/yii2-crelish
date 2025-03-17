<?php

namespace app\workspace\models;

use yii\db\ActiveRecord;

/**
 * This file demonstrates how to create a database model for use with the new Crelish CMS storage system.
 * 
 * To use database storage for a content type, you need to:
 * 1. Create a model class that extends ActiveRecord
 * 2. Set the storage property to 'db' in the element definition
 */

/**
 * Article model
 * 
 * @property string $uuid UUID
 * @property string $title Title
 * @property string $content Content
 * @property int $state State
 * @property int $created Created timestamp
 * @property int $updated Updated timestamp
 * @property string $slug URL slug
 */
class Article extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'article';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['uuid', 'title'], 'required'],
            [['content'], 'string'],
            [['state', 'created', 'updated'], 'integer'],
            [['uuid', 'title', 'slug'], 'string', 'max' => 255],
            [['uuid'], 'unique'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'uuid' => 'UUID',
            'title' => 'Title',
            'content' => 'Content',
            'state' => 'State',
            'created' => 'Created',
            'updated' => 'Updated',
            'slug' => 'Slug',
        ];
    }

    /**
     * Get the author relation
     * 
     * @return \yii\db\ActiveQuery
     */
    public function getAuthor()
    {
        return $this->hasOne(User::class, ['uuid' => 'author_uuid']);
    }

    /**
     * Get the categories relation
     * 
     * @return \yii\db\ActiveQuery
     */
    public function getCategories()
    {
        return $this->hasMany(Category::class, ['uuid' => 'category_uuid'])
            ->viaTable('article_category', ['article_uuid' => 'uuid']);
    }
}

/**
 * Example element definition (article.json):
 * 
 * {
 *   "key": "article",
 *   "label": "Article",
 *   "storage": "db",
 *   "fields": [
 *     {
 *       "label": "Title",
 *       "key": "title",
 *       "type": "textInput",
 *       "visibleInGrid": true,
 *       "sortable": true,
 *       "rules": [["required"], ["string", {"max": 255}]]
 *     },
 *     {
 *       "label": "Content",
 *       "key": "content",
 *       "type": "textarea",
 *       "visibleInGrid": false,
 *       "rules": [["string"]]
 *     },
 *     {
 *       "label": "Author",
 *       "key": "author_uuid",
 *       "type": "relationSelect",
 *       "visibleInGrid": true,
 *       "sortable": true,
 *       "config": {
 *         "ctype": "user",
 *         "valueField": "uuid",
 *         "labelField": "name",
 *         "multiple": false
 *       },
 *       "rules": [["required"]]
 *     },
 *     {
 *       "label": "Categories",
 *       "key": "categories",
 *       "type": "relationSelect",
 *       "visibleInGrid": true,
 *       "config": {
 *         "ctype": "category",
 *         "valueField": "uuid",
 *         "labelField": "name",
 *         "multiple": true
 *       },
 *       "rules": [["safe"]]
 *     },
 *     {
 *       "label": "Slug",
 *       "key": "slug",
 *       "type": "textInput",
 *       "visibleInGrid": true,
 *       "rules": [["string", {"max": 255}]]
 *     }
 *   ],
 *   "sortDefault": {
 *     "created": "SORT_DESC"
 *   }
 * } 