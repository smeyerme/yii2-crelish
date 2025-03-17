# Crelish CMS Examples

This directory contains examples of how to use the new Crelish CMS storage system.

## Files

- `basic_usage.php`: Demonstrates basic usage of the new storage system
- `database_model.php`: Shows how to create a database model for use with the new storage system
- `widget_usage.php`: Demonstrates how to use the ElementNav widget

## ElementNav Widget

The ElementNav widget has been updated to directly scan the elements directory instead of using the data storage system. The widget's API remains the same, but internally it now uses a more direct approach to read element definitions.

### What the Widget Does

The ElementNav widget:

1. Scans the `@app/workspace/elements/` directory for JSON files
2. Reads each JSON file to extract element definitions
3. Shows a navigation for all element types that have the attribute `"selectable": true` (or don't have the attribute at all, as it defaults to true)
4. Sorts the elements by their label for better organization

### Changes Made

The following changes were made to the ElementNav widget:

1. Removed dependency on `CrelishDataManager` or `CrelishDataProvider`
2. Added direct file scanning using `FileHelper::findFiles()`
3. Added JSON parsing of element definition files
4. Added sorting of elements by label
5. Maintained backward compatibility with the existing API

### Example Usage

```php
// Basic usage
echo ElementNav::widget();

// Advanced usage with configuration
echo ElementNav::widget([
    'action' => 'index',
    'selector' => 'content_type',
    'ctype' => 'article',
    'target' => '#customContentSelector'
]);
```

See `widget_usage.php` for more detailed examples. 