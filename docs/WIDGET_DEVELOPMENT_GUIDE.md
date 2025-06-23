# Crelish Widget Development Guide

## Overview

This guide explains the improved architecture for creating Crelish widgets that are:
- Reusable across different contexts (forms, JsonStructureEditor, etc.)
- Based on Yii2's InputWidget for better form integration
- Following consistent patterns and interfaces
- Easy to maintain and extend

## Architecture Components

### 1. Base Classes

#### CrelishInputWidget
The new base class for all form widgets, extending Yii2's `InputWidget`:

```php
use giantbits\crelish\components\CrelishInputWidget;

class MyWidget extends CrelishInputWidget
{
    // Your implementation
}
```

**Key features:**
- Extends Yii2's InputWidget for proper form integration
- Implements CrelishWidgetInterface
- Handles common tasks (asset registration, data processing, etc.)
- Provides helper methods for common operations

#### CrelishAbstractContentProcessor
Base class for content processors:

```php
use giantbits\crelish\components\CrelishAbstractContentProcessor;

class MyWidgetContentProcessor extends CrelishAbstractContentProcessor
{
    // Your implementation
}
```

### 2. Interfaces

#### CrelishWidgetInterface
Defines the contract for all widgets:

```php
interface CrelishWidgetInterface
{
    public function processData($data);
    public function getValue();
    public function setValue($value);
    public function getFieldDefinition();
    public function registerAssets();
    public function renderWidget();
    public function getInitializationScript();
    public function supportsAjaxRendering();
    public function getClientConfig();
}
```

#### CrelishContentProcessorInterface
Defines the contract for content processors:

```php
interface CrelishContentProcessorInterface
{
    public static function processData($key, $data, &$processedData, $fieldConfig = null);
    public static function processDataPreSave($key, $data, $fieldConfig, &$parent);
    public static function processDataPostSave($key, $data, $fieldConfig, &$parent);
    public static function processJson($ctype, $key, $data, &$processedData);
}
```

### 3. Strategy Pattern

Different rendering strategies for different contexts:

- **StandardRenderStrategy**: For regular form rendering
- **JsonStructureRenderStrategy**: For rendering within JsonStructureEditor
- Custom strategies can be created for other contexts

### 4. Widget Factory

The `CrelishWidgetFactory` handles widget creation and rendering:

```php
$factory = new CrelishWidgetFactory();
$widget = $factory->createWidget($fieldDef, $model, $value);
$html = $factory->renderWidget($widget);
```

## Creating a New Widget

### Step 1: Create the Widget Class

```php
<?php
namespace app\widgets;

use giantbits\crelish\components\CrelishInputWidget;
use yii\helpers\Html;

class ColorPickerWidget extends CrelishInputWidget
{
    /**
     * @var string Default color
     */
    public $defaultColor = '#000000';
    
    /**
     * {@inheritdoc}
     */
    protected function registerWidgetAssets()
    {
        // Register your CSS/JS files
        $this->assetPath = '@app/widgets/assets/colorpicker.js';
        parent::registerWidgetAssets();
    }
    
    /**
     * {@inheritdoc}
     */
    public function processData($data)
    {
        // Process incoming data
        if (empty($data)) {
            return $this->defaultColor;
        }
        
        // Validate color format
        if (preg_match('/^#[0-9A-F]{6}$/i', $data)) {
            return $data;
        }
        
        return $this->defaultColor;
    }
    
    /**
     * {@inheritdoc}
     */
    public function renderWidget()
    {
        $options = [
            'id' => $this->getInputId(),
            'name' => $this->getInputName(),
            'value' => $this->getValue(),
            'class' => 'form-control color-picker-input',
            'data-default-color' => $this->defaultColor,
        ];
        
        if ($this->isRequired()) {
            $options['required'] = true;
        }
        
        return Html::textInput($this->getInputName(), $this->getValue(), $options);
    }
    
    /**
     * {@inheritdoc}
     */
    public function getInitializationScript()
    {
        $id = $this->getInputId();
        return "
            if (typeof ColorPicker !== 'undefined') {
                new ColorPicker('#{$id}');
            }
        ";
    }
}
```

### Step 2: Create the Content Processor

```php
<?php
namespace app\widgets;

use giantbits\crelish\components\CrelishAbstractContentProcessor;

class ColorPickerWidgetContentProcessor extends CrelishAbstractContentProcessor
{
    /**
     * {@inheritdoc}
     */
    public static function processData($key, $data, &$processedData, $fieldConfig = null)
    {
        // Process data for display
        if (empty($data)) {
            $processedData[$key] = '#000000';
        } else {
            $processedData[$key] = $data;
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public static function processDataPreSave($key, $data, $fieldConfig, &$parent)
    {
        // Validate before saving
        if (!preg_match('/^#[0-9A-F]{6}$/i', $data)) {
            $parent[$key] = '#000000';
        } else {
            $parent[$key] = strtoupper($data);
        }
    }
}
```

### Step 3: Register the Widget

In your application configuration:

```php
use giantbits\crelish\components\CrelishWidgetFactory;

// Register the widget type
CrelishWidgetFactory::registerWidget('colorPicker', 'app\widgets\ColorPickerWidget');
```

### Step 4: Use in Field Definitions

In your JSON schema:

```json
{
  "fields": [
    {
      "key": "primaryColor",
      "label": "Primary Color",
      "type": "colorPicker",
      "config": {
        "defaultColor": "#FF0000"
      }
    }
  ]
}
```

Or with explicit class:

```json
{
  "fields": [
    {
      "key": "primaryColor",
      "label": "Primary Color",
      "type": "widget",
      "widgetClass": "app\\widgets\\ColorPickerWidget",
      "widgetOptions": {
        "defaultColor": "#FF0000"
      }
    }
  ]
}
```

## Using Widgets in JsonStructureEditor

The new architecture makes widgets automatically compatible with JsonStructureEditor:

```json
{
  "fields": [
    {
      "key": "theme",
      "label": "Theme Settings",
      "type": "jsonStructureEditor",
      "config": {
        "schema": {
          "fields": [
            {
              "key": "primaryColor",
              "label": "Primary Color",
              "type": "colorPicker"
            },
            {
              "key": "logo",
              "label": "Logo",
              "type": "assetConnector"
            }
          ]
        }
      }
    }
  ]
}
```

## Best Practices

### 1. Data Processing
- Always validate input data in `processData()`
- Return normalized data from `processData()`
- Handle null/empty values gracefully

### 2. Asset Management
- Use `registerWidgetAssets()` for registering CSS/JS
- Assets are only registered once per widget type
- Use `$this->assetPath` for simple cases

### 3. JavaScript Integration
- Implement `getInitializationScript()` for client-side init
- Check if libraries exist before using them
- Use unique IDs to avoid conflicts

### 4. AJAX Support
- Return `true` from `supportsAjaxRendering()` if your widget works via AJAX
- Implement `getClientConfig()` to provide data for client-side init
- Ensure your widget can be initialized after DOM insertion

### 5. Backward Compatibility
- Keep property names consistent with existing widgets
- Support both old and new initialization methods
- Maintain existing public methods

## Migration Guide

### Migrating Existing Widgets

1. Change base class from `CrelishFormWidget` to `CrelishInputWidget`
2. Implement required interface methods
3. Move initialization logic to appropriate methods
4. Update asset registration to use new patterns

Example migration:

```php
// Old
class AssetConnector extends CrelishFormWidget
{
    public function init()
    {
        parent::init();
        // Asset registration here
    }
    
    public function run()
    {
        // Rendering here
    }
}

// New
class AssetConnectorV2 extends CrelishInputWidget
{
    protected function registerWidgetAssets()
    {
        // Asset registration here
    }
    
    public function renderWidget()
    {
        // Rendering here
    }
}
```

## Testing

### Unit Testing

```php
class ColorPickerWidgetTest extends TestCase
{
    public function testProcessData()
    {
        $widget = new ColorPickerWidget([
            'model' => new DynamicModel(['color' => null]),
            'attribute' => 'color'
        ]);
        
        $this->assertEquals('#000000', $widget->processData(null));
        $this->assertEquals('#FF0000', $widget->processData('#FF0000'));
        $this->assertEquals('#000000', $widget->processData('invalid'));
    }
}
```

### Integration Testing

Test your widget in different contexts:
1. Regular form usage
2. Within JsonStructureEditor
3. AJAX rendering
4. Multiple instances on same page

## Troubleshooting

### Common Issues

1. **Assets not loading**
   - Check `$this->assetPath` is correct
   - Ensure assets are published with `forceCopy` in debug mode

2. **Widget not initializing in JsonStructureEditor**
   - Implement `supportsAjaxRendering()` 
   - Provide proper `getInitializationScript()`
   - Check JavaScript console for errors

3. **Data not saving correctly**
   - Verify ContentProcessor is implemented
   - Check field name matches in all places
   - Ensure proper data format in `processDataPreSave()`

## Examples

See the following widgets for reference implementations:
- `AssetConnectorV2` - Complex widget with Vue.js
- `RelationSelect` - Widget with dynamic data loading
- `JsonStructureEditor` - Nested widget support

## Future Enhancements

Planned improvements:
1. Widget preview in admin interface
2. Visual widget designer
3. Widget marketplace/sharing
4. Automated testing framework
5. Performance optimizations for large forms