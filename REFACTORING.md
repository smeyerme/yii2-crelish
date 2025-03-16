# Crelish CMS Refactoring

This document explains the refactoring of the Crelish CMS data layer to improve maintainability and support both JSON file storage and database storage.

## Overview

The original Crelish CMS was designed as a flat-file CMS using JSON files for storage. Later, database support was added, but this led to code duplication and maintenance issues. This refactoring introduces a clean abstraction layer for data storage, allowing the CMS to work with either storage mechanism transparently.

## Key Changes

### 1. Storage Interface

A new `CrelishDataStorage` interface has been created to define a common contract for all storage implementations. This ensures that all storage mechanisms provide the same functionality.

### 2. Storage Implementations

Two concrete implementations of the storage interface have been created:

- `CrelishJsonStorage`: Handles storage in JSON files
- `CrelishDbStorage`: Handles storage in a database

### 3. Storage Factory

A new `CrelishStorageFactory` class has been created to handle the creation of the appropriate storage implementation based on the content type's configuration.

### 4. Unified Data Manager

A new `CrelishDataManager` class has been created to replace the old data providers. This class provides a unified interface for working with content, regardless of the underlying storage mechanism.

### 5. Updated Data Resolver

The `CrelishDataResolver` class has been updated to use the new storage factory and provide a consistent interface for resolving models and data providers.

### 6. Updated Dynamic Model

The `CrelishDynamicModel` class has been updated to use the new storage system for saving and deleting records.

## Benefits

- **Reduced code duplication**: Common functionality is now in the interface and abstract classes
- **Improved maintainability**: Changes to storage logic only need to be made in one place
- **Cleaner architecture**: Clear separation of concerns between data access and business logic
- **Easier to extend**: New storage mechanisms can be added by implementing the interface
- **Consistent API**: All storage mechanisms provide the same interface

## Usage

### Working with Content

```php
// Get a data manager for a content type
$dataManager = new CrelishDataManager('article', [
    'filter' => ['state' => 2], // Only published articles
    'sort' => ['by' => ['created', 'desc']], // Sort by creation date, newest first
]);

// Get all articles
$articles = $dataManager->all();

// Get a data provider for GridView
$dataProvider = $dataManager->getProvider();

// Get a single article
$dataManager = new CrelishDataManager('article', [], $uuid);
$article = $dataManager->one();
```

### Working with Models

```php
// Create a new model
$model = new CrelishDynamicModel([], ['ctype' => 'article']);
$model->title = 'New Article';
$model->content = 'Article content';
$model->save();

// Update an existing model
$model = new CrelishDynamicModel([], ['ctype' => 'article', 'uuid' => $uuid]);
$model->title = 'Updated Title';
$model->save();

// Delete a model
$model = new CrelishDynamicModel([], ['ctype' => 'article', 'uuid' => $uuid]);
$model->delete();
```

## Configuration

To specify the storage mechanism for a content type, add a `storage` property to the element definition:

```json
{
  "key": "article",
  "label": "Article",
  "storage": "db",
  "fields": [
    // Field definitions
  ]
}
```

If the `storage` property is not specified or is set to anything other than `db`, the content type will use JSON file storage by default. 