# Crelish Plugins System

## Overview

The Crelish CMS uses a powerful plugin system for creating reusable form widgets and content processors. This system allows you to create custom field types that can be used across different contexts including regular forms, the JsonStructureEditor, and any future interfaces.

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [Plugin Types](#plugin-types)
- [Creating a New Plugin](#creating-a-new-plugin)
- [Using Existing Plugins](#using-existing-plugins)
- [Plugin Structure](#plugin-structure)
- [Interfaces and Base Classes](#interfaces-and-base-classes)
- [Strategy Pattern](#strategy-pattern)
- [Widget Factory](#widget-factory)
- [Field Configuration](#field-configuration)
- [Asset Management](#asset-management)
- [JavaScript Integration](#javascript-integration)
- [Content Processing](#content-processing)
- [JsonStructureEditor Integration](#jsonstructureeditor-integration)
- [Migration Guide](#migration-guide)
- [Best Practices](#best-practices)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)

## Architecture Overview

The Crelish plugin system is built around these core concepts:

1. **Widgets**: Form input components that extend `CrelishInputWidget`
2. **Content Processors**: Classes that handle data transformation and validation
3. **Interfaces**: Contracts that ensure consistent plugin behavior
4. **Strategies**: Different rendering approaches for different contexts
5. **Factory**: Centralized widget creation and management

```
Plugin Architecture:
┌─────────────────────────────────────────────────────────────┐
│                     Application Layer                       │
├─────────────────────────────────────────────────────────────┤
│  CrelishBaseController │ JsonStructureEditor │ Other Views  │
├─────────────────────────────────────────────────────────────┤
│                   CrelishWidgetFactory                     │
├─────────────────────────────────────────────────────────────┤
│        Render Strategies (Standard, JsonStructure)         │
├─────────────────────────────────────────────────────────────┤
│                    Widget Instances                        │
│    ┌─────────────┬─────────────┬─────────────────────────┐  │
│    │AssetConnect │RelationSel  │YourCustomWidget         │  │
│    │or           │ect          │                         │  │
│    └─────────────┴─────────────┴─────────────────────────┘  │
├─────────────────────────────────────────────────────────────┤
│                   Base Classes                             │
│           CrelishInputWidget │ CrelishAbstractContentProc  │
├─────────────────────────────────────────────────────────────┤
│                    Interfaces                              │
│     CrelishWidgetInterface │ CrelishContentProcessorIntf   │
├─────────────────────────────────────────────────────────────┤
│                   Yii2 Framework                           │
│              InputWidget │ Component                       │
└─────────────────────────────────────────────────────────────┘
```

## Plugin Types

### 1. Form Input Widgets

These are the main components users interact with:

- **AssetConnector**: File/media selection widget
- **RelationSelect**: Dropdown for selecting related content
- **JsonStructureEditor**: Complex nested data structures
- **WidgetConnector**: Generic widget integration

### 2. Content Processors

Handle data transformation between storage and display:

- Process data before rendering (e.g., load related models)
- Validate and transform data before saving
- Handle post-save operations (e.g., cleanup, notifications)

## Creating a New Plugin

### Step 1: Create Plugin Directory

```
plugins/
└── myplugin/
    ├── MyPlugin.php                    # Main widget class
    ├── MyPluginContentProcessor.php    # Content processor
    ├── views/                          # Twig templates (optional)
    │   └── widget.twig
    ├── assets/                         # JavaScript/CSS files
    │   ├── myplugin.js
    │   └── myplugin.css
    └── README.md                       # Plugin documentation
```

### Step 2: Create the Widget Class

```php
<?php
namespace giantbits\crelish\plugins\myplugin;

use giantbits\crelish\components\CrelishInputWidget;
use yii\helpers\Html;
use Yii;

class MyPlugin extends CrelishInputWidget
{
    /**
     * @var string Custom property
     */
    public $customProperty = 'default';
    
    /**
     * {@inheritdoc}
     */
    protected function registerWidgetAssets()
    {
        // Register JavaScript file
        $this->assetPath = Yii::getAlias('@vendor/giantbits/yii2-crelish/plugins/myplugin/assets/myplugin.js');
        parent::registerWidgetAssets();
        
        // Register CSS file
        $cssPath = Yii::getAlias('@vendor/giantbits/yii2-crelish/plugins/myplugin/assets/myplugin.css');
        $this->view->registerCssFile($cssPath);
    }
    
    /**
     * {@inheritdoc}
     */
    public function processData($data)
    {
        // Process and validate incoming data
        if (empty($data)) {
            return $this->getConfig('defaultValue', '');
        }
        
        // Your data processing logic here
        return $data;
    }
    
    /**
     * {@inheritdoc}
     */
    public function renderWidget()
    {
        // Option 1: Render using Twig template
        return $this->renderView('widget', [
            'customProperty' => $this->customProperty,
        ]);
        
        // Option 2: Render HTML directly
        /*
        return Html::textInput(
            $this->getInputName(),
            $this->getValue(),
            [
                'id' => $this->getInputId(),
                'class' => 'form-control my-plugin-input',
                'data-custom' => $this->customProperty,
            ]
        );
        */
    }
    
    /**
     * {@inheritdoc}
     */
    public function getInitializationScript()
    {
        $id = $this->getInputId();
        return "
            if (typeof MyPlugin !== 'undefined') {
                new MyPlugin('#{$id}', {
                    customProperty: '{$this->customProperty}'
                });
            }
        ";
    }
    
    /**
     * {@inheritdoc}
     */
    protected function loadTranslations()
    {
        $this->translations = [
            'selectValue' => Yii::t('app', 'Select Value'),
            'clearValue' => Yii::t('app', 'Clear'),
            // Add more translations as needed
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function getClientConfig()
    {
        $config = parent::getClientConfig();
        $config['customProperty'] = $this->customProperty;
        return $config;
    }
}
```

### Step 3: Create the Content Processor

```php
<?php
namespace giantbits\crelish\plugins\myplugin;

use giantbits\crelish\components\CrelishAbstractContentProcessor;

class MyPluginContentProcessor extends CrelishAbstractContentProcessor
{
    /**
     * {@inheritdoc}
     */
    public static function processData($key, $data, &$processedData, $fieldConfig = null)
    {
        // Process data for display
        if (empty($data)) {
            $processedData[$key] = static::getFieldConfig($fieldConfig, 'defaultValue', '');
            return;
        }
        
        // Your processing logic here
        $processedData[$key] = $data;
    }
    
    /**
     * {@inheritdoc}
     */
    public static function processDataPreSave($key, $data, $fieldConfig, &$parent)
    {
        // Validate and transform data before saving
        if (empty($data)) {
            unset($parent[$key]); // Remove empty values
            return;
        }
        
        // Your validation/transformation logic here
        $parent[$key] = trim($data);
    }
    
    /**
     * {@inheritdoc}
     */
    public static function processDataPostSave($key, $data, $fieldConfig, &$parent)
    {
        // Handle post-save operations (optional)
        // e.g., send notifications, update search index, etc.
    }
}
```

### Step 4: Create Twig Template (Optional)

```twig
{# plugins/myplugin/views/widget.twig #}
<div class="form-group field-{{ inputId }}{{ required ? ' required' : '' }}">
    <div class="my-plugin-container" 
         data-field-key="{{ field.key }}"
         data-custom="{{ customProperty }}">
        
        <input type="text" 
               id="{{ inputId }}"
               name="{{ inputName }}"
               value="{{ value }}"
               class="form-control my-plugin-input"
               {{ required ? 'required' : '' }}>
        
        <div class="my-plugin-controls">
            <button type="button" class="btn btn-sm btn-secondary my-plugin-select">
                {{ widget.t('selectValue') }}
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary my-plugin-clear">
                {{ widget.t('clearValue') }}
            </button>
        </div>
    </div>
    
    <div class="help-block help-block-error"></div>
</div>
```

### Step 5: Create JavaScript File

```javascript
// plugins/myplugin/assets/myplugin.js
class MyPlugin {
    constructor(selector, options = {}) {
        this.container = document.querySelector(selector);
        this.options = options;
        this.init();
    }
    
    init() {
        if (!this.container) return;
        
        // Find elements
        this.input = this.container.querySelector('.my-plugin-input');
        this.selectBtn = this.container.querySelector('.my-plugin-select');
        this.clearBtn = this.container.querySelector('.my-plugin-clear');
        
        // Bind events
        this.bindEvents();
    }
    
    bindEvents() {
        if (this.selectBtn) {
            this.selectBtn.addEventListener('click', () => this.handleSelect());
        }
        
        if (this.clearBtn) {
            this.clearBtn.addEventListener('click', () => this.handleClear());
        }
        
        if (this.input) {
            this.input.addEventListener('change', () => this.handleChange());
        }
    }
    
    handleSelect() {
        // Your selection logic here
        console.log('Select clicked');
    }
    
    handleClear() {
        if (this.input) {
            this.input.value = '';
            this.input.dispatchEvent(new Event('change'));
        }
    }
    
    handleChange() {
        // Handle value changes
        console.log('Value changed:', this.input.value);
    }
}

// Make globally available
window.MyPlugin = MyPlugin;
```

### Step 6: Register the Plugin

```php
// In your application configuration or initialization
use giantbits\crelish\components\CrelishWidgetFactory;

CrelishWidgetFactory::registerWidget('myPlugin', 'giantbits\\crelish\\plugins\\myplugin\\MyPlugin');
```

## Using Existing Plugins

### In Field Definitions (JSON)

```json
{
  "fields": [
    {
      "key": "featured_image",
      "label": "Featured Image",
      "type": "assetConnector",
      "config": {
        "accept": "image/*"
      },
      "rules": [["required"]]
    },
    {
      "key": "related_articles",
      "label": "Related Articles",
      "type": "relationSelect",
      "config": {
        "ctype": "article",
        "multiple": true
      }
    },
    {
      "key": "custom_field",
      "label": "Custom Field",
      "type": "myPlugin",
      "config": {
        "customProperty": "special_value"
      }
    }
  ]
}
```

### With Explicit Widget Class

```json
{
  "fields": [
    {
      "key": "advanced_field",
      "label": "Advanced Field",
      "type": "widget",
      "widgetClass": "app\\widgets\\AdvancedWidget",
      "widgetOptions": {
        "setting1": "value1",
        "setting2": "value2"
      }
    }
  ]
}
```

### In JsonStructureEditor Schemas

```json
{
  "title": "Product Schema",
  "fields": [
    {
      "key": "name",
      "label": "Product Name",
      "type": "text"
    },
    {
      "key": "image",
      "label": "Product Image",
      "type": "assetConnector"
    }
  ],
  "arrays": [
    {
      "key": "variants",
      "label": "Product Variants",
      "itemLabel": "Variant",
      "itemSchema": {
        "fields": [
          {
            "key": "variant_image",
            "label": "Variant Image", 
            "type": "assetConnector"
          },
          {
            "key": "related_products",
            "label": "Related Products",
            "type": "relationSelect",
            "config": {
              "ctype": "product",
              "multiple": true
            }
          }
        ]
      }
    }
  ]
}
```

## Plugin Structure

### Directory Layout

```
plugins/
├── assetconnector/
│   ├── AssetConnector.php
│   ├── AssetConnectorV2.php              # New architecture version
│   ├── AssetConnectorContentProcessor.php
│   └── views/
│       └── widget.twig
├── relationselect/
│   ├── RelationSelect.php
│   ├── RelationSelectContentProcessor.php
│   └── views/
├── jsonstructureeditor/
│   ├── JsonStructureEditor.php
│   ├── JsonStructureEditorContentProcessor.php
│   └── schemas/                          # Example schemas
├── widgetconnector/
│   └── WidgetConnector.php
└── README.md                             # This file
```

### Naming Conventions

- **Widget Class**: `PluginName.php` (e.g., `AssetConnector.php`)
- **Content Processor**: `PluginNameContentProcessor.php`
- **Namespace**: `giantbits\crelish\plugins\pluginname`
- **Type Name**: Usually lowercase plugin name (e.g., `assetConnector`)

## Interfaces and Base Classes

### CrelishWidgetInterface

Defines the contract all widgets must implement:

```php
interface CrelishWidgetInterface
{
    public function processData($data);              // Process incoming data
    public function getValue();                      // Get current value
    public function setValue($value);                // Set value
    public function getFieldDefinition();            // Get field config
    public function registerAssets();                // Register CSS/JS
    public function renderWidget();                  // Render HTML
    public function getInitializationScript();       // Get init JS
    public function supportsAjaxRendering();         // AJAX support flag
    public function getClientConfig();               // Client-side config
}
```

### CrelishInputWidget

Base class providing common functionality:

```php
abstract class CrelishInputWidget extends InputWidget implements CrelishWidgetInterface
{
    // Common properties
    public $data;                    // Raw data value
    public $rawData;                 // For backward compatibility  
    public $formKey;                 // Form field key
    public $field;                   // Field definition object
    public $value;                   // Current value
    public $widgetOptions = [];      // Widget-specific options
    
    // Helper methods
    protected function getInputName();              // Get form input name
    protected function getInputId();                // Get form input ID
    protected function isRequired();                // Check if required
    protected function getConfig($key, $default);   // Get field config
    protected function getOption($key, $default);   // Get widget option
    protected function normalizeToArray($value);    // Convert to array
    // ... more helpers
}
```

### CrelishContentProcessorInterface

Defines content processor contract:

```php
interface CrelishContentProcessorInterface
{
    public static function processData($key, $data, &$processedData, $fieldConfig = null);
    public static function processDataPreSave($key, $data, $fieldConfig, &$parent);
    public static function processDataPostSave($key, $data, $fieldConfig, &$parent);
    public static function processJson($ctype, $key, $data, &$processedData);
}
```

### CrelishAbstractContentProcessor

Base class with common functionality:

```php
abstract class CrelishAbstractContentProcessor extends Component implements CrelishContentProcessorInterface
{
    // Helper methods
    protected static function isUuid($value);                    // Check UUID format
    protected static function isJson($value);                    // Check JSON format
    protected static function safeJsonDecode($json);             // Safe JSON decode
    protected static function safeJsonEncode($data);             // Safe JSON encode
    protected static function loadRelatedModel($uuid, $ctype);   // Load related model
    protected static function processArray($items, $processor);  // Process arrays
    protected static function getFieldConfig($config, $key);     // Get config value
}
```

## Strategy Pattern

Different rendering strategies for different contexts:

### StandardRenderStrategy

For regular form rendering:

```php
$strategy = new StandardRenderStrategy();
$factory = new CrelishWidgetFactory($strategy);
$html = $factory->widget($fieldDef, $model, $value);
```

### JsonStructureRenderStrategy

For JsonStructureEditor context:

```php
$strategy = new JsonStructureRenderStrategy('json-editor', ['path', 'to', 'field']);
$factory = new CrelishWidgetFactory($strategy);
$html = $factory->widget($fieldDef, $model, $value);
```

### Custom Strategies

Create custom strategies for specific contexts:

```php
class CustomRenderStrategy implements WidgetRenderStrategy
{
    public function render(CrelishWidgetInterface $widget, array $context = [])
    {
        // Your custom rendering logic
    }
    
    public function supports(CrelishWidgetInterface $widget)
    {
        // Check if this strategy supports the widget
    }
    
    public function getInitScript(CrelishWidgetInterface $widget, array $context = [])
    {
        // Return initialization script
    }
}
```

## Widget Factory

Centralized widget creation and management:

### Basic Usage

```php
use giantbits\crelish\components\CrelishWidgetFactory;

$factory = new CrelishWidgetFactory();

// Create widget
$widget = $factory->createWidget($fieldDef, $model, $value);

// Render widget
$html = $factory->renderWidget($widget);

// Or do both in one step
$html = $factory->widget($fieldDef, $model, $value);
```

### With Custom Strategy

```php
$strategy = new JsonStructureRenderStrategy();
$factory = new CrelishWidgetFactory($strategy);
$html = $factory->widget($fieldDef, $model, $value);
```

### Widget Registration

```php
// Register by type
CrelishWidgetFactory::registerWidget('customType', 'app\\widgets\\CustomWidget');

// Check if type is registered
if (CrelishWidgetFactory::isWidget('customType')) {
    // Handle as widget
}
```

## Field Configuration

### Field Definition Structure

```php
$fieldDef = [
    'key' => 'field_name',           // Required: field identifier
    'label' => 'Field Label',        // Display label
    'type' => 'assetConnector',      // Widget type
    'required' => true,              // Whether field is required
    'rules' => [                     // Validation rules
        ['required'],
        ['string', ['max' => 255]]
    ],
    'config' => [                    // Widget-specific configuration
        'accept' => 'image/*',
        'multiple' => false
    ],
    'widgetOptions' => [             // Direct widget properties
        'customProperty' => 'value'
    ],
    'placeholder' => 'Enter value',  // Input placeholder
    'default' => 'default_value'     // Default value
];
```

### Configuration Access

In your widget:

```php
// Get config value with default
$accept = $this->getConfig('accept', '*/*');

// Get widget option
$customProp = $this->getOption('customProperty', 'default');

// Check if required
$required = $this->isRequired();

// Get field definition
$field = $this->getFieldDefinition();
```

## Asset Management

### Automatic Asset Registration

```php
class MyWidget extends CrelishInputWidget
{
    protected function registerWidgetAssets()
    {
        // Set asset path - will be auto-published and registered
        $this->assetPath = Yii::getAlias('@vendor/giantbits/yii2-crelish/plugins/mywidget/assets/script.js');
        parent::registerWidgetAssets();
        
        // Register CSS manually
        $cssPath = Yii::getAlias('@vendor/giantbits/yii2-crelish/plugins/mywidget/assets/style.css');
        $this->view->registerCssFile($cssPath);
    }
}
```

### Manual Asset Registration

```php
protected function registerWidgetAssets()
{
    $assetManager = Yii::$app->assetManager;
    
    // Publish and register JavaScript
    $jsPath = Yii::getAlias('@vendor/giantbits/yii2-crelish/plugins/mywidget/assets/script.js');
    $publishedUrl = $assetManager->publish($jsPath, [
        'forceCopy' => YII_DEBUG,
        'appendTimestamp' => true,
    ])[1];
    $this->view->registerJsFile($publishedUrl);
    
    // Register CSS
    $cssPath = Yii::getAlias('@vendor/giantbits/yii2-crelish/plugins/mywidget/assets/style.css');
    $this->view->registerCssFile($cssPath);
}
```

### Asset Bundle (Advanced)

```php
class MyWidgetAsset extends AssetBundle
{
    public $sourcePath = '@vendor/giantbits/yii2-crelish/plugins/mywidget/assets';
    public $js = ['script.js'];
    public $css = ['style.css'];
    public $depends = ['yii\web\JqueryAsset'];
}

// In widget
protected function registerWidgetAssets()
{
    MyWidgetAsset::register($this->view);
}
```

## JavaScript Integration

### Initialization Script

```php
public function getInitializationScript()
{
    $id = $this->getInputId();
    $config = Json::encode($this->getClientConfig());
    
    return "
        if (typeof MyWidget !== 'undefined') {
            new MyWidget('#{$id}', {$config});
        }
    ";
}
```

### Client Configuration

```php
public function getClientConfig()
{
    $config = parent::getClientConfig();
    
    // Add widget-specific config
    $config['apiEndpoint'] = '/api/mywidget';
    $config['customSetting'] = $this->customSetting;
    
    return $config;
}
```

### Event Handling

```javascript
class MyWidget {
    constructor(selector, config) {
        this.element = document.querySelector(selector);
        this.config = config;
        this.init();
    }
    
    init() {
        // Initialize widget
        this.bindEvents();
    }
    
    bindEvents() {
        this.element.addEventListener('change', (e) => {
            this.handleChange(e);
        });
        
        // Custom events
        this.element.addEventListener('mywidget:update', (e) => {
            this.handleUpdate(e.detail);
        });
    }
    
    handleChange(event) {
        // Notify parent components
        this.element.dispatchEvent(new CustomEvent('widget:changed', {
            detail: { value: event.target.value }
        }));
    }
    
    setValue(value) {
        this.element.value = value;
        this.element.dispatchEvent(new Event('change'));
    }
}
```

## Content Processing

### Data Flow

1. **Raw Data** → `processData()` → **Display Data**
2. **Form Data** → `processDataPreSave()` → **Storage Data**
3. **Saved Data** → `processDataPostSave()` → **Side Effects**

### Processing Examples

```php
class MyWidgetContentProcessor extends CrelishAbstractContentProcessor
{
    public static function processData($key, $data, &$processedData, $fieldConfig = null)
    {
        // Transform data for display
        if (static::isUuid($data)) {
            // Load related model
            $model = static::loadRelatedModel($data, 'content');
            $processedData[$key] = $model ?: $data;
        } else {
            $processedData[$key] = $data;
        }
    }
    
    public static function processDataPreSave($key, $data, $fieldConfig, &$parent)
    {
        // Validate and transform before saving
        if (empty($data)) {
            unset($parent[$key]);
            return;
        }
        
        // Clean and validate
        $cleaned = strip_tags(trim($data));
        $parent[$key] = $cleaned;
    }
    
    public static function processDataPostSave($key, $data, $fieldConfig, &$parent)
    {
        // Handle side effects after saving
        if (!empty($data)) {
            // Update search index
            // Send notifications
            // Clear caches
        }
    }
}
```

## JsonStructureEditor Integration

### Automatic Integration

Widgets automatically work in JsonStructureEditor:

```json
{
  "fields": [
    {
      "key": "settings",
      "type": "jsonStructureEditor",
      "config": {
        "schema": {
          "fields": [
            {
              "key": "logo",
              "label": "Logo",
              "type": "assetConnector"
            },
            {
              "key": "theme_color",
              "label": "Theme Color",
              "type": "colorPicker"
            }
          ]
        }
      }
    }
  ]
}
```

### AJAX Rendering Support

For widgets that need server-side rendering:

```php
public function supportsAjaxRendering()
{
    return true; // Enable AJAX rendering
}

public function getClientConfig()
{
    return [
        'fieldKey' => $this->formKey,
        'value' => $this->getValue(),
        'config' => $this->getConfig(),
        // ... other config
    ];
}
```

### Custom Rendering Strategy

```php
// In JsonStructureEditor context
$strategy = new JsonStructureRenderStrategy('json-editor-' . $editorId, $fieldPath);
$factory = new CrelishWidgetFactory($strategy);
$html = $factory->widget($fieldDef, $model, $value);
```

## Migration Guide

### From Old Architecture

1. **Change Base Class**:
   ```php
   // Old
   class MyWidget extends CrelishFormWidget
   
   // New  
   class MyWidget extends CrelishInputWidget
   ```

2. **Update Method Names**:
   ```php
   // Old
   public function run() { /* render logic */ }
   
   // New
   public function renderWidget() { /* render logic */ }
   ```

3. **Move Asset Registration**:
   ```php
   // Old
   public function init() {
       parent::init();
       // asset registration
   }
   
   // New
   protected function registerWidgetAssets() {
       // asset registration
   }
   ```

4. **Implement Required Methods**:
   ```php
   public function processData($data) { /* ... */ }
   public function getInitializationScript() { /* ... */ }
   public function supportsAjaxRendering() { return true; }
   public function getClientConfig() { /* ... */ }
   ```

### Backward Compatibility

The new architecture maintains backward compatibility:

- Existing widgets continue to work
- Old method names are preserved
- Properties remain the same
- Field configurations unchanged

## Best Practices

### 1. Data Handling

```php
// ✅ Good: Validate and normalize data
public function processData($data)
{
    if (empty($data)) {
        return $this->getConfig('defaultValue', '');
    }
    
    if (is_string($data)) {
        return trim($data);
    }
    
    return $data;
}

// ❌ Bad: No validation
public function processData($data)
{
    return $data;
}
```

### 2. Asset Management

```php
// ✅ Good: Register assets only once
protected function registerWidgetAssets()
{
    $this->assetPath = Yii::getAlias('@vendor/giantbits/yii2-crelish/plugins/mywidget/assets/script.js');
    parent::registerWidgetAssets();
}

// ❌ Bad: Register assets every time
public function run()
{
    $this->view->registerJsFile('/path/to/script.js');
    return $this->renderWidget();
}
```

### 3. JavaScript Integration

```php
// ✅ Good: Check for dependencies
public function getInitializationScript()
{
    $id = $this->getInputId();
    return "
        if (typeof MyWidget !== 'undefined') {
            new MyWidget('#{$id}');
        } else {
            console.warn('MyWidget library not loaded');
        }
    ";
}

// ❌ Bad: Assume dependencies exist
public function getInitializationScript()
{
    return "new MyWidget('#{$this->getInputId()}');";
}
```

### 4. Error Handling

```php
// ✅ Good: Graceful error handling
public function renderWidget()
{
    try {
        return $this->renderView('widget');
    } catch (\Exception $e) {
        Yii::error("Widget render error: " . $e->getMessage());
        return Html::tag('div', 'Widget error', ['class' => 'alert alert-danger']);
    }
}
```

### 5. Configuration

```php
// ✅ Good: Use helper methods
$accept = $this->getConfig('accept', '*/*');
$multiple = $this->getConfig('multiple', false);

// ❌ Bad: Direct property access
$accept = $this->field->config->accept ?? '*/*';
```

## Testing

### Unit Tests

```php
class MyWidgetTest extends TestCase
{
    public function testProcessData()
    {
        $widget = new MyWidget([
            'model' => new DynamicModel(['test' => null]),
            'attribute' => 'test'
        ]);
        
        // Test empty data
        $this->assertEquals('', $widget->processData(null));
        
        // Test valid data
        $this->assertEquals('test', $widget->processData('test'));
        
        // Test invalid data
        $this->assertEquals('', $widget->processData(['invalid']));
    }
    
    public function testRenderWidget()
    {
        $widget = new MyWidget([
            'model' => new DynamicModel(['test' => 'value']),
            'attribute' => 'test'
        ]);
        
        $html = $widget->renderWidget();
        
        $this->assertStringContainsString('form-group', $html);
        $this->assertStringContainsString('value', $html);
    }
}
```

### Integration Tests

```php
class MyWidgetIntegrationTest extends TestCase
{
    public function testFormIntegration()
    {
        // Test widget in regular form
        $factory = new CrelishWidgetFactory();
        $html = $factory->widget($fieldDef, $model, $value);
        
        $this->assertStringContainsString('my-widget', $html);
    }
    
    public function testJsonStructureIntegration()
    {
        // Test widget in JsonStructureEditor
        $strategy = new JsonStructureRenderStrategy();
        $factory = new CrelishWidgetFactory($strategy);
        $html = $factory->widget($fieldDef, $model, $value);
        
        $this->assertStringContainsString('crelish-widget-placeholder', $html);
    }
}
```

### JavaScript Tests

```javascript
// Using Jest or similar framework
describe('MyWidget', () => {
    let widget;
    let container;
    
    beforeEach(() => {
        container = document.createElement('div');
        container.innerHTML = '<input type="text" id="test-input">';
        document.body.appendChild(container);
        
        widget = new MyWidget('#test-input');
    });
    
    afterEach(() => {
        document.body.removeChild(container);
    });
    
    test('initializes correctly', () => {
        expect(widget.element).toBeTruthy();
    });
    
    test('handles value changes', () => {
        widget.setValue('test');
        expect(widget.element.value).toBe('test');
    });
});
```

## Troubleshooting

### Common Issues

#### 1. Widget Not Rendering

**Symptoms**: Widget shows as text or empty
**Causes**: 
- Widget class not found
- Missing namespace declaration
- Asset loading issues

**Solutions**:
```php
// Check class exists
if (!class_exists($widgetClass)) {
    throw new InvalidConfigException("Widget class not found: $widgetClass");
}

// Verify namespace
namespace giantbits\crelish\plugins\myplugin;

// Check asset path
$this->assetPath = Yii::getAlias('@vendor/giantbits/yii2-crelish/plugins/myplugin/assets/script.js');
if (!file_exists(Yii::getAlias($this->assetPath))) {
    throw new InvalidConfigException("Asset file not found: {$this->assetPath}");
}
```

#### 2. JavaScript Not Initializing

**Symptoms**: Widget renders but doesn't work
**Causes**:
- JavaScript errors
- Missing dependencies
- Initialization script not running

**Solutions**:
```php
public function getInitializationScript()
{
    $id = $this->getInputId();
    return "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof MyWidget !== 'undefined') {
                new MyWidget('#{$id}');
            } else {
                console.error('MyWidget not available');
            }
        });
    ";
}
```

#### 3. Data Not Saving

**Symptoms**: Form submits but data not persisted
**Causes**:
- Input name incorrect
- ContentProcessor not processing data
- Validation errors

**Solutions**:
```php
// Use correct input name
$inputName = $this->getInputName(); // CrelishDynamicModel[fieldname]

// Implement content processor
public static function processDataPreSave($key, $data, $fieldConfig, &$parent)
{
    $parent[$key] = $data; // Ensure data is saved
}
```

#### 4. JsonStructureEditor Integration Issues

**Symptoms**: Widget doesn't work in JsonStructureEditor
**Causes**:
- AJAX rendering not supported
- Initialization script missing
- Client config incomplete

**Solutions**:
```php
public function supportsAjaxRendering()
{
    return true;
}

public function getClientConfig()
{
    return [
        'widgetClass' => get_class($this),
        'fieldKey' => $this->formKey,
        'value' => $this->getValue(),
        'options' => $this->widgetOptions,
    ];
}
```

### Debugging Tools

#### 1. Enable Debug Mode

```php
// In config
'components' => [
    'assetManager' => [
        'forceCopy' => YII_DEBUG,
        'appendTimestamp' => YII_DEBUG,
    ],
],
```

#### 2. Add Logging

```php
public function renderWidget()
{
    Yii::debug("Rendering widget: " . get_class($this), __METHOD__);
    $html = $this->renderView('widget');
    Yii::debug("Rendered HTML length: " . strlen($html), __METHOD__);
    return $html;
}
```

#### 3. Console Debugging

```javascript
class MyWidget {
    constructor(selector, config) {
        console.log('MyWidget initialized:', selector, config);
        // ... rest of constructor
    }
}
```

### Performance Optimization

#### 1. Asset Optimization

```php
// Use asset bundles for multiple files
class MyWidgetAsset extends AssetBundle
{
    public $sourcePath = '@vendor/giantbits/yii2-crelish/plugins/mywidget/assets';
    public $js = ['script.min.js'];
    public $css = ['style.min.css'];
    public $depends = ['yii\web\JqueryAsset'];
}
```

#### 2. Lazy Loading

```javascript
// Load heavy dependencies only when needed
class MyWidget {
    async init() {
        if (!window.HeavyLibrary) {
            await this.loadHeavyLibrary();
        }
        this.setupWidget();
    }
}
```

#### 3. Caching

```php
// Cache expensive operations
protected function getExpensiveData()
{
    $cacheKey = 'mywidget_data_' . $this->getValue();
    return Yii::$app->cache->getOrSet($cacheKey, function() {
        return $this->calculateExpensiveData();
    }, 3600);
}
```

## Contributing

When contributing new plugins or improvements:

1. Follow the naming conventions
2. Implement all required interfaces
3. Include comprehensive tests
4. Document configuration options
5. Provide usage examples
6. Ensure backward compatibility

### Submission Checklist

- [ ] Widget extends `CrelishInputWidget`
- [ ] Content processor extends `CrelishAbstractContentProcessor`
- [ ] All interface methods implemented
- [ ] Asset registration follows patterns
- [ ] JavaScript integration works
- [ ] Works in JsonStructureEditor
- [ ] Unit tests included
- [ ] Documentation updated
- [ ] Examples provided

For more detailed examples and advanced usage, see the individual plugin directories and the main [Widget Development Guide](../docs/WIDGET_DEVELOPMENT_GUIDE.md).