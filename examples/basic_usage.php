<?php

use giantbits\crelish\components\CrelishDataManager;
use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishStorageFactory;

/**
 * This file demonstrates basic usage of the new Crelish CMS storage system.
 */

// Example 1: Working with the Data Manager

// Create a data manager for articles
$articleManager = new CrelishDataManager('article', [
    'filter' => ['state' => 2], // Only published articles
    'sort' => ['by' => ['created', 'desc']], // Sort by creation date, newest first
]);

// Get all articles with pagination
$result = $articleManager->all();
$articles = $result['models'];
$pagination = $result['pagination'];

// Get a data provider for GridView
$dataProvider = $articleManager->getProvider();

// Get a single article
$singleArticleManager = new CrelishDataManager('article', [], 'some-uuid');
$article = $singleArticleManager->one();

// Example 2: Working with Models

// Create a new article
$newArticle = new CrelishDynamicModel([], ['ctype' => 'article']);
$newArticle->title = 'New Article';
$newArticle->content = 'This is a new article created with the new storage system.';
$newArticle->state = 1; // Draft
$newArticle->save();

// Update an existing article
$existingArticle = new CrelishDynamicModel([], ['ctype' => 'article', 'uuid' => 'some-uuid']);
$existingArticle->title = 'Updated Title';
$existingArticle->state = 2; // Published
$existingArticle->save();

// Delete an article
$articleToDelete = new CrelishDynamicModel([], ['ctype' => 'article', 'uuid' => 'some-uuid']);
$articleToDelete->delete();

// Example 3: Direct Storage Access

// Get the appropriate storage implementation for a content type
$storage = CrelishStorageFactory::getStorage('article');

// Find a record
$record = $storage->findOne('article', 'some-uuid');

// Find all records with filtering and sorting
$records = $storage->findAll('article', ['state' => 2], ['by' => ['created', 'desc']]);

// Save a record
$data = [
    'uuid' => 'some-uuid',
    'title' => 'Direct Storage Access',
    'content' => 'This article was created using direct storage access.',
    'state' => 1,
];
$storage->save('article', $data, true); // true = new record

// Delete a record
$storage->delete('article', 'some-uuid');

// Example 4: Advanced Filtering

// Filter by multiple criteria
$advancedManager = new CrelishDataManager('article', [
    'filter' => [
        'state' => ['strict', 2], // Exactly state 2
        'created' => ['gt', strtotime('-1 week')], // Created in the last week
        'category' => 'news', // Category contains 'news'
        'freesearch' => 'important topic', // Full-text search
    ],
]);
$filteredArticles = $advancedManager->all();

// Example 5: Working with Relations

// Get articles with their authors
$articlesWithAuthors = new CrelishDataManager('article', [
    'filter' => ['state' => 2],
]);
$dataProvider = $articlesWithAuthors->getProvider();

// Set relations for the query (if using database storage)
$query = $dataProvider->query;
$articlesWithAuthors->setRelations($query); 