# Migration Guide for Crelish CMS 2.0

This guide will help you migrate your code from the old Crelish CMS data handling system to the new unified storage system introduced in version 2.0.

## Overview of Changes

In version 2.0, we've completely refactored the data handling layer to improve maintainability and provide a consistent interface for both JSON file storage and database storage. The key changes are:

1. Introduction of a unified `CrelishDataStorage` interface
2. Implementation of specific storage classes (`CrelishJsonStorage` and `CrelishDbStorage`)
3. Creation of a factory class (`CrelishStorageFactory`) to handle storage implementation selection
4. Introduction of a new `CrelishDataManager` class as the main entry point for data operations

## Deprecated Classes

The following classes are now deprecated and will be removed in a future version:

- `CrelishDataProvider`
- `CrelishJsonDataProvider`
- `CrelishDynamicJsonModel`

These classes have been updated to use the new storage system internally, so your existing code will continue to work, but you should migrate to the new classes as soon as possible.

## Migration Steps

### 1. Replace Data Provider Usage

**Before:**
```php
// Using CrelishDataProvider
$dataProvider = new CrelishDataProvider('article', [
    'filter' => ['state' => 2],
    'sort' => ['by' => ['created', 'desc']]
]);

// Using CrelishJsonDataProvider
$dataProvider = new CrelishJsonDataProvider('article', [
    'filter' => ['state' => 2],
    'sort' => ['by' => ['created', 'desc']]
]);
```

**After:**
```php
// Using CrelishDataManager
$dataManager = new CrelishDataManager('article', [
    'filter' => ['state' => 2],
    'sort' => ['by' => ['created', 'desc']]
]);
```

### 2. Replace Data Provider Methods

**Before:**
```php
// Get all records
$result = $dataProvider->all();
$models = $result['models'];
$pager = $result['pager'];

// Get a single record
$model = $dataProvider->one();

// Get a data provider for GridView
$provider = $dataProvider->getProvider();

// Get raw data
$rawData = $dataProvider->rawAll();
```

**After:**
```php
// Get all records
$result = $dataManager->all();
$models = $result['models'];
$pager = $result['pagination'];

// Get a single record
$model = $dataManager->one();

// Get a data provider for GridView
$provider = $dataManager->getProvider();

// Get raw data
$rawData = $dataManager->rawAll();
```

### 3. Replace Model Usage

**Before:**
```php
// Using CrelishDynamicJsonModel
$model = new CrelishDynamicJsonModel([], [
    'ctype' => 'article',
    'uuid' => $uuid
]);

// Save a model
$model->title = 'New Title';
$model->save();

// Delete a model
$model->delete();
```

**After:**
```php
// Using CrelishDynamicModel
$model = new CrelishDynamicModel([], [
    'ctype' => 'article',
    'uuid' => $uuid
]);

// Save a model
$model->title = 'New Title';
$model->save();

// Delete a model
$model->delete();
```

### 4. Update Element Definitions

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

### 5. Update Widgets

Several widgets have been updated to use the new storage system. Here's how to update your code:

#### ElementNav Widget

The ElementNav widget has been updated to directly scan the elements directory instead of using the data storage system.

**Before:**
```php
// Using ElementNav with the old system
echo ElementNav::widget([
    'action' => 'index',
    'selector' => 'ctype',
    'ctype' => 'page'
]);
```

**After:**
```php
// Using ElementNav with the new system
echo ElementNav::widget([
    'action' => 'index',
    'selector' => 'ctype',
    'ctype' => 'page'
]);
```

The widget's API remains the same, but internally it now uses a more direct approach to read element definitions. The widget scans the `@app/workspace/elements/` directory for JSON files, reads each file to extract element definitions, and shows a navigation for all element types that have the attribute `"selectable": true` (or don't have the attribute at all, as it defaults to true).

## Advanced Usage

### Direct Storage Access

If you need direct access to the storage implementation, you can use the `CrelishStorageFactory`:

```php
// Get the appropriate storage implementation for a content type
$storage = CrelishStorageFactory::getStorage('article');

// Find a record
$record = $storage->findOne('article', $uuid);

// Find all records with filtering and sorting
$records = $storage->findAll('article', $filter, $sort);

// Save a record
$storage->save('article', $data, $isNew);

// Delete a record
$storage->delete('article', $uuid);
```

### Custom Storage Implementations

If you need to implement a custom storage mechanism, you can create a new class that implements the `CrelishDataStorage` interface and register it with the factory:

```php
// Create a custom storage implementation
class MyCustomStorage implements CrelishDataStorage
{
    // Implement all required methods
}

// Register it with the factory (you'll need to extend the factory)
class MyStorageFactory extends CrelishStorageFactory
{
    protected static function createStorage(string $storageType): CrelishDataStorage
    {
        if ($storageType === 'custom') {
            return new MyCustomStorage();
        }
        
        return parent::createStorage($storageType);
    }
}
```

## Troubleshooting

### Missing Methods

If you're using methods that were available in the old classes but are not available in the new ones, you can:

1. Check if the functionality is available through a different method
2. Extend the new classes to add the missing functionality
3. Continue using the deprecated classes until you can refactor your code

### Performance Issues

The new storage system is designed to be more efficient, but if you encounter performance issues:

1. Make sure you're using the appropriate storage implementation for your content type
2. Check if you're using the caching mechanisms correctly
3. Consider implementing a custom storage implementation optimized for your specific use case

## Need Help?

If you need help migrating your code or have questions about the new storage system, please:

1. Check the documentation
2. Look at the example code
3. Open an issue on GitHub

We're here to help you make the transition as smooth as possible! 