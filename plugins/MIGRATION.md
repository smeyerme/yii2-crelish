# Migration Guide: V1 to V2 Widgets

This document provides guidance on migrating from the existing V1 widgets to the new V2 architecture based on Yii2's InputWidget.

## Overview

The V2 architecture introduces:
- **CrelishInputWidget** base class extending Yii2's InputWidget
- **CrelishWidgetInterface** for consistent contracts
- **Content Processors** for data handling
- **Factory Pattern** for widget creation
- **Strategy Pattern** for rendering contexts

## Should You Migrate?

**Recommendation: Gradual Migration**

You don't need to immediately replace all V1 widgets. The V2 architecture is designed for backward compatibility:

1. **Keep V1 widgets** for existing functionality that works
2. **Use V2 widgets** for new features and JsonStructureEditor integration
3. **Migrate gradually** when you need enhanced features

## Migration Strategy

### Option 1: Coexistence (Recommended)

Keep both versions and let the factory choose:

```php
// CrelishWidgetFactory will automatically prefer V2 when available
$widget = CrelishWidgetFactory::createWidget('assetConnector', $config);
```

### Option 2: Direct Migration

Replace V1 imports with V2:

```php
// Old
use giantbits\crelish\plugins\assetconnector\AssetConnector;

// New  
use giantbits\crelish\plugins\assetconnector\AssetConnectorV2;
```

### Option 3: Alias Migration

Create aliases in your config for gradual transition:

```php
// In your Yii2 config
'container' => [
    'definitions' => [
        'AssetConnector' => 'AssetConnectorV2',
        'RelationSelect' => 'RelationSelectV2',
        'WidgetConnector' => 'WidgetConnectorV2',
    ]
]
```

## Widget-Specific Migration

### AssetConnector → AssetConnectorV2

**Key Differences:**
- Extends `CrelishInputWidget` instead of `Widget`
- Better Vue.js integration
- Improved asset management
- Content processor for data handling

**Migration Steps:**
1. Update imports: `AssetConnector` → `AssetConnectorV2`
2. Update form field configs (minimal changes needed)
3. Test asset selection and Vue component initialization

**Breaking Changes:**
- None - API remains compatible

### RelationSelect → RelationSelectV2

**Key Differences:**
- Better data normalization
- Enhanced Select2 integration
- Table view support
- Improved content processing

**Migration Steps:**
1. Update imports: `RelationSelect` → `RelationSelectV2`
2. Update field config if using advanced features
3. Test multiple/single selection modes

**Breaking Changes:**
- None for basic usage
- Advanced configurations may need adjustment

### WidgetConnector → WidgetConnectorV2

**Key Differences:**
- Better widget embedding
- Improved data format handling
- Enhanced content processing

**Migration Steps:**
1. Update imports: `WidgetConnector` → `WidgetConnectorV2`
2. Review data format configurations
3. Test embedded widget functionality

**Breaking Changes:**
- Data format handling is more strict
- Some edge cases in widget embedding may behave differently

## Form Field Configuration

V2 widgets maintain backward compatibility with existing field configurations:

```json
{
  "type": "assetConnector",
  "label": "Main Image",
  "required": true,
  "config": {
    "assetType": "image",
    "accept": "image/*"
  }
}
```

## JsonStructureEditor Integration

V2 widgets work seamlessly with JsonStructureEditor:

```json
{
  "type": "jsonStructureEditor",
  "schema": {
    "properties": {
      "mainImage": {
        "type": "assetConnector",
        "config": {
          "assetType": "image"
        }
      },
      "relatedItems": {
        "type": "relationSelect", 
        "config": {
          "ctype": "product",
          "multiple": true
        }
      }
    }
  }
}
```

## Testing Migration

### 1. Unit Tests

Test each V2 widget independently:

```php
public function testAssetConnectorV2Migration()
{
    $widget = new AssetConnectorV2([
        'model' => $this->model,
        'attribute' => 'image',
        'data' => ['uuid' => 'test-uuid']
    ]);
    
    $output = $widget->run();
    $this->assertStringContainsString('asset-connector-container', $output);
}
```

### 2. Integration Tests

Test with JsonStructureEditor:

```php
public function testJsonStructureEditorWithV2Widgets()
{
    $editor = new JsonStructureEditor([
        'model' => $this->model,
        'attribute' => 'data',
        'schema' => [
            'properties' => [
                'image' => ['type' => 'assetConnector']
            ]
        ]
    ]);
    
    $output = $editor->run();
    $this->assertStringContainsString('json-structure-editor', $output);
}
```

### 3. Browser Tests

- Test widget initialization
- Test Vue.js component mounting
- Test data submission and validation
- Test AJAX interactions

## Performance Considerations

V2 widgets offer improved performance:

- **Better asset loading**: Widgets load assets more efficiently
- **Reduced memory usage**: Better object lifecycle management  
- **Faster rendering**: Optimized HTML generation
- **Improved caching**: Better content processor caching

## Troubleshooting

### Common Issues

**Vue.js not initializing:**
- Ensure Vue.js is loaded before widget scripts
- Check browser console for JavaScript errors
- Verify widget containers have correct data attributes

**Data not saving:**
- Check content processor implementation
- Verify field configuration matches widget expectations
- Test data validation rules

**Styling issues:**
- V2 widgets may have slightly different CSS classes
- Review and update custom styles if needed

**Widget not found:**
- Ensure V2 widget classes are properly autoloaded
- Check namespace imports
- Verify widget registration in factory

### Debug Mode

Enable debug mode to see widget loading process:

```php
// In your config
'components' => [
    'crelish' => [
        'debug' => true,
        'widgetDebug' => true
    ]
]
```

## Rollback Strategy

If issues arise, you can easily rollback:

1. **File-level rollback**: Change imports back to V1 widgets
2. **Configuration rollback**: Update widget factory mappings
3. **Database rollback**: V2 widgets use same data formats as V1

## Timeline Recommendations

**Phase 1 (Week 1-2):** Test V2 widgets in development
- Install and test each V2 widget individually
- Test JsonStructureEditor integration
- Run comprehensive browser tests

**Phase 2 (Week 3-4):** Gradual production rollout  
- Deploy with both V1 and V2 widgets available
- Use V2 for new features only
- Monitor for any issues

**Phase 3 (Month 2-3):** Full migration
- Migrate existing forms to V2 widgets
- Update documentation and training
- Deprecate V1 widgets

**Phase 4 (Month 4+):** Cleanup
- Remove V1 widget code
- Update codebase documentation
- Performance optimization

## Conclusion

The V2 widget architecture provides significant improvements while maintaining backward compatibility. The gradual migration approach allows you to benefit from new features without breaking existing functionality.

Start with testing V2 widgets in JsonStructureEditor, then gradually migrate existing forms as needed.