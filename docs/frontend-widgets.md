# Frontend Widgets

Frontend widgets are reusable components that render content on the public-facing website. They live in `workspace/widgets/` and can be selected and configured through the CMS admin interface using the `WidgetConnector` plugin.

## Table of Contents

1. [Overview](#overview)
2. [Widget Location](#widget-location)
3. [Legacy Widgets](#legacy-widgets)
4. [Configurable Widgets](#configurable-widgets)
5. [ConfigurableWidgetInterface](#configurablewidgetinterface)
6. [Configuration Schema](#configuration-schema)
7. [Using Widgets in Templates](#using-widgets-in-templates)
8. [WidgetConnector Plugin](#widgetconnector-plugin)
9. [Complete Examples](#complete-examples)
10. [Migration Guide](#migration-guide)

---

## Overview

Crelish supports two types of frontend widgets:

| Type | Interface | CMS Configuration | Use Case |
|------|-----------|-------------------|----------|
| **Legacy** | `yii\base\Widget` | No | Simple widgets with hardcoded or template-passed config |
| **Configurable** | `ConfigurableWidgetInterface` | Yes | Widgets with dynamic CMS-managed configuration |

---

## Widget Location

All frontend widgets must be placed in:

```
workspace/widgets/{WidgetName}/{WidgetName}.php
```

The directory name must match the class name exactly.

### Directory Structure

```
workspace/widgets/
├── CourseList/
│   ├── CourseList.php           # Widget class
│   ├── _courses.twig            # View template
│   └── _registration.twig       # Additional template
├── ServicesList/
│   ├── ServicesList.php         # Widget class (configurable)
│   ├── _services_items.twig     # Full layout template
│   └── _services_compact.twig   # Compact layout template
└── ContactForm/
    ├── ContactForm.php          # Widget class
    └── form.twig                # View template
```

---

## Legacy Widgets

Legacy widgets extend `yii\base\Widget` without implementing the configuration interface. They work but cannot be configured through the CMS.

### Basic Structure

```php
<?php

namespace app\workspace\widgets\MyWidget;

use yii\base\Widget;

class MyWidget extends Widget
{
    // Public properties for configuration (passed via template or hardcoded)
    public string $mode = 'default';
    public int $limit = 10;

    public function run(): string
    {
        $items = $this->fetchData();

        return $this->render('_list.twig', [
            'items' => $items,
            'mode' => $this->mode,
        ]);
    }

    private function fetchData(): array
    {
        // Fetch and return data
        return [];
    }
}
```

### Usage in Templates

```twig
{# Pass configuration directly in template #}
{{ widget('app\\workspace\\widgets\\MyWidget\\MyWidget', {
    'mode': 'compact',
    'limit': 5
}) }}
```

### Characteristics

- Listed in WidgetConnector as "Legacy widget (not configurable)"
- Configuration must be passed in code/templates
- No dynamic form generation in CMS
- Still fully functional, just not CMS-configurable

---

## Configurable Widgets

Configurable widgets implement `ConfigurableWidgetInterface` to expose their configuration to the CMS admin.

### Basic Structure

```php
<?php

namespace app\workspace\widgets\MyWidget;

use giantbits\crelish\components\ConfigurableWidgetInterface;
use yii\base\Widget;
use yii\helpers\Json;

class MyWidget extends Widget implements ConfigurableWidgetInterface
{
    // Configuration properties with defaults
    public $displayMode = 'full';
    public $itemLimit = 10;
    public $showHeader = true;
    public $headerTitle = 'Default Title';

    // Receives configuration from CMS
    public $data;

    /**
     * Widget metadata for CMS display
     */
    public static function getWidgetMeta(): array
    {
        return [
            'label' => 'My Widget',
            'description' => 'Displays items in various layouts',
            'category' => 'content',
            'icon' => 'list',
        ];
    }

    /**
     * Configuration schema for dynamic form generation
     */
    public static function getConfigSchema(): array
    {
        return [
            'displayMode' => [
                'type' => 'select',
                'label' => 'Display Mode',
                'options' => [
                    'full' => 'Full (with description)',
                    'compact' => 'Compact (title only)',
                ],
                'default' => 'full',
            ],
            'itemLimit' => [
                'type' => 'number',
                'label' => 'Item Limit',
                'default' => 10,
                'min' => 1,
                'max' => 100,
            ],
            'showHeader' => [
                'type' => 'checkbox',
                'label' => 'Show Header',
                'default' => true,
            ],
            'headerTitle' => [
                'type' => 'text',
                'label' => 'Header Title',
                'default' => 'Default Title',
                'dependsOn' => ['showHeader' => true],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Parse configuration from CMS
        if (!empty($this->data)) {
            $data = is_string($this->data) ? Json::decode($this->data) : $this->data;
            if (is_array($data)) {
                $this->displayMode = $data['displayMode'] ?? $this->displayMode;
                $this->itemLimit = $data['itemLimit'] ?? $this->itemLimit;
                $this->showHeader = $data['showHeader'] ?? $this->showHeader;
                $this->headerTitle = $data['headerTitle'] ?? $this->headerTitle;
            }
        }
    }

    public function run(): string
    {
        $items = $this->fetchData();

        return $this->render('_list.twig', [
            'items' => $items,
            'config' => [
                'displayMode' => $this->displayMode,
                'showHeader' => $this->showHeader,
                'headerTitle' => $this->headerTitle,
            ],
        ]);
    }

    private function fetchData(): array
    {
        return MyModel::find()
            ->where(['state' => 2])
            ->limit($this->itemLimit)
            ->all();
    }
}
```

---

## ConfigurableWidgetInterface

The interface requires two static methods:

### getWidgetMeta()

Returns metadata about the widget for display in the CMS:

```php
public static function getWidgetMeta(): array
{
    return [
        'label' => 'Widget Name',           // Required: Human-readable name
        'description' => 'What it does',    // Optional: Short description
        'category' => 'content',            // Optional: For grouping (content, media, navigation)
        'icon' => 'list',                   // Optional: Icon identifier
    ];
}
```

### getConfigSchema()

Returns the configuration schema for dynamic form generation:

```php
public static function getConfigSchema(): array
{
    return [
        'fieldKey' => [
            'type' => 'text',           // Required: Field type
            'label' => 'Field Label',   // Required: Display label
            'default' => 'value',       // Optional: Default value
            'required' => false,        // Optional: Is field required
            'hint' => 'Help text',      // Optional: Help text below field
            // ... type-specific options
        ],
    ];
}
```

---

## Configuration Schema

### Supported Field Types

| Type | Description | Type-Specific Options |
|------|-------------|----------------------|
| `text` | Single line text | `placeholder` |
| `textarea` | Multi-line text | `placeholder`, `rows` |
| `number` | Numeric input | `min`, `max`, `step` |
| `select` | Dropdown selection | `options` (value => label array) |
| `checkbox` | Boolean toggle | - |
| `radio` | Radio button group | `options` (value => label array) |
| `color` | Color picker | - |
| `asset` | Asset/file selector | - |
| `relation` | Content type selector | `ctype`, `multiple`, `displayField` |

### Common Field Options

```php
'fieldKey' => [
    'type' => 'text',                    // Required
    'label' => 'Field Label',            // Required
    'default' => 'default value',        // Default value
    'required' => true,                  // Validation
    'hint' => 'Helpful description',     // Help text
    'placeholder' => 'Enter value...',   // Placeholder text
    'dependsOn' => ['otherField' => true], // Conditional visibility
],
```

### Conditional Visibility (dependsOn)

Show/hide fields based on other field values:

```php
'showHeader' => [
    'type' => 'checkbox',
    'label' => 'Show Header',
    'default' => true,
],
'headerTitle' => [
    'type' => 'text',
    'label' => 'Header Title',
    // Only visible when showHeader is true
    'dependsOn' => ['showHeader' => true],
],
'headerStyle' => [
    'type' => 'select',
    'label' => 'Header Style',
    // Only visible when showHeader is true
    'dependsOn' => ['showHeader' => true],
    'options' => [
        'light' => 'Light',
        'dark' => 'Dark',
    ],
],
```

### Relation Fields

Select content from other content types:

```php
'selectedItems' => [
    'type' => 'relation',
    'label' => 'Select Items',
    'ctype' => 'event',              // Content type to select from
    'multiple' => true,              // Allow multiple selections
    'displayField' => 'systitle',    // Field to display in selector
    'hint' => 'Leave empty to show all',
],
'category' => [
    'type' => 'relation',
    'label' => 'Category',
    'ctype' => 'eventcategory',
    'multiple' => false,             // Single selection
],
```

---

## Using Widgets in Templates

### Via WidgetConnector (CMS-managed)

When using the `widgetConnector` field type in your content type, the widget is rendered automatically:

```twig
{# In your page template #}
{% if content.widgetType %}
    {{ widget('app\\workspace\\widgets\\' ~ content.widgetType ~ '\\' ~ content.widgetType, {
        'data': content.options
    }) }}
{% endif %}
```

### Direct Usage

```twig
{# Legacy widget #}
{{ widget('app\\workspace\\widgets\\CourseList\\CourseList', {
    'action': 'overview'
}) }}

{# Configurable widget with inline config #}
{{ widget('app\\workspace\\widgets\\ServicesList\\ServicesList', {
    'displayMode': 'compact',
    'showHeader': false
}) }}

{# Configurable widget with JSON config #}
{{ widget('app\\workspace\\widgets\\ServicesList\\ServicesList', {
    'data': '{"displayMode":"full","showHeader":true}'
}) }}
```

---

## WidgetConnector Plugin

The `WidgetConnector` is a CMS form plugin that:

1. Scans `workspace/widgets/` for available widgets
2. Renders a dropdown to select widget type
3. Dynamically generates configuration form based on `getConfigSchema()`
4. Stores `widgetType` + `options` (JSON) in the content record

### Using WidgetConnector in Element Definition

```json
{
  "key": "widgetpage",
  "storage": "db",
  "label": "Widget Pages",
  "fields": [
    {
      "label": "Title",
      "key": "systitle",
      "type": "textInput"
    },
    {
      "label": "Widget",
      "key": "widgetType",
      "type": "widgetConnector",
      "rules": [["string", {"max": 128}]]
    },
    {
      "label": "Widget Options",
      "key": "options",
      "type": "textarea",
      "visible": false
    }
  ]
}
```

---

## Complete Examples

### Example 1: Simple Configurable Widget

```php
<?php

namespace app\workspace\widgets\LatestNews;

use app\workspace\models\News;
use giantbits\crelish\components\ConfigurableWidgetInterface;
use yii\base\Widget;
use yii\helpers\Json;

class LatestNews extends Widget implements ConfigurableWidgetInterface
{
    public $limit = 5;
    public $showImages = true;
    public $category = null;
    public $data;

    public static function getWidgetMeta(): array
    {
        return [
            'label' => 'Latest News',
            'description' => 'Displays the most recent news articles',
            'category' => 'content',
            'icon' => 'newspaper',
        ];
    }

    public static function getConfigSchema(): array
    {
        return [
            'limit' => [
                'type' => 'number',
                'label' => 'Number of articles',
                'default' => 5,
                'min' => 1,
                'max' => 20,
            ],
            'showImages' => [
                'type' => 'checkbox',
                'label' => 'Show featured images',
                'default' => true,
            ],
            'category' => [
                'type' => 'relation',
                'label' => 'Filter by category',
                'ctype' => 'newscategory',
                'multiple' => false,
                'hint' => 'Leave empty to show all categories',
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (!empty($this->data)) {
            $data = is_string($this->data) ? Json::decode($this->data) : $this->data;
            if (is_array($data)) {
                $this->limit = $data['limit'] ?? $this->limit;
                $this->showImages = $data['showImages'] ?? $this->showImages;
                $this->category = $data['category'] ?? $this->category;
            }
        }
    }

    public function run(): string
    {
        $query = News::find()
            ->where(['state' => 2])
            ->orderBy(['created' => SORT_DESC])
            ->limit($this->limit);

        if ($this->category) {
            $query->andWhere(['category' => $this->category]);
        }

        return $this->render('_news.twig', [
            'items' => $query->all(),
            'showImages' => $this->showImages,
        ]);
    }
}
```

### Example 2: Widget with Multiple Display Modes

```php
<?php

namespace app\workspace\widgets\TeamMembers;

use app\workspace\models\TeamMember;
use giantbits\crelish\components\ConfigurableWidgetInterface;
use yii\base\Widget;
use yii\helpers\Json;

class TeamMembers extends Widget implements ConfigurableWidgetInterface
{
    public $layout = 'grid';
    public $columns = 3;
    public $members = [];
    public $showBio = true;
    public $showSocial = true;
    public $data;

    public static function getWidgetMeta(): array
    {
        return [
            'label' => 'Team Members',
            'description' => 'Display team members in grid or list layout',
            'category' => 'content',
            'icon' => 'users',
        ];
    }

    public static function getConfigSchema(): array
    {
        return [
            'layout' => [
                'type' => 'select',
                'label' => 'Layout',
                'options' => [
                    'grid' => 'Grid',
                    'list' => 'List',
                    'carousel' => 'Carousel',
                ],
                'default' => 'grid',
            ],
            'columns' => [
                'type' => 'select',
                'label' => 'Columns',
                'options' => [
                    '2' => '2 Columns',
                    '3' => '3 Columns',
                    '4' => '4 Columns',
                ],
                'default' => '3',
                'dependsOn' => ['layout' => 'grid'],
            ],
            'members' => [
                'type' => 'relation',
                'label' => 'Select Members',
                'ctype' => 'teammember',
                'multiple' => true,
                'displayField' => 'systitle',
                'hint' => 'Leave empty to show all active members',
            ],
            'showBio' => [
                'type' => 'checkbox',
                'label' => 'Show Biography',
                'default' => true,
            ],
            'showSocial' => [
                'type' => 'checkbox',
                'label' => 'Show Social Links',
                'default' => true,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (!empty($this->data)) {
            $data = is_string($this->data) ? Json::decode($this->data) : $this->data;
            if (is_array($data)) {
                foreach (['layout', 'columns', 'members', 'showBio', 'showSocial'] as $prop) {
                    if (isset($data[$prop])) {
                        $this->$prop = $data[$prop];
                    }
                }
            }
        }
    }

    public function run(): string
    {
        $query = TeamMember::find()->where(['state' => 2]);

        if (!empty($this->members)) {
            $uuids = is_array($this->members) ? $this->members : [$this->members];
            $query->andWhere(['uuid' => $uuids]);
        }

        $query->orderBy(['sorting' => SORT_ASC]);

        $template = match ($this->layout) {
            'list' => '_list.twig',
            'carousel' => '_carousel.twig',
            default => '_grid.twig',
        };

        return $this->render($template, [
            'members' => $query->all(),
            'columns' => (int)$this->columns,
            'showBio' => $this->showBio,
            'showSocial' => $this->showSocial,
        ]);
    }
}
```

---

## Migration Guide

### Converting Legacy Widget to Configurable

1. **Add the interface:**
   ```php
   use giantbits\crelish\components\ConfigurableWidgetInterface;

   class MyWidget extends Widget implements ConfigurableWidgetInterface
   ```

2. **Add `$data` property:**
   ```php
   public $data;
   ```

3. **Implement `getWidgetMeta()`:**
   ```php
   public static function getWidgetMeta(): array
   {
       return [
           'label' => 'My Widget',
           'description' => 'Description here',
           'category' => 'content',
       ];
   }
   ```

4. **Implement `getConfigSchema()` based on existing public properties:**
   ```php
   public static function getConfigSchema(): array
   {
       return [
           'existingProp' => [
               'type' => 'text',
               'label' => 'Existing Property',
               'default' => 'default value',
           ],
       ];
   }
   ```

5. **Update `init()` to parse `$data`:**
   ```php
   public function init(): void
   {
       parent::init();

       if (!empty($this->data)) {
           $data = is_string($this->data) ? Json::decode($this->data) : $this->data;
           if (is_array($data)) {
               // Map each config property
               $this->existingProp = $data['existingProp'] ?? $this->existingProp;
           }
       }
   }
   ```

---

## See Also

- [Working with Widgets](./widgets.md) - General widget concepts
- [Frontend Integration](./frontend-integration.md) - Templates and rendering
- [Extending Crelish](./extending.md) - Custom plugins and components
- [Twig Reference](./twig-reference.md) - Template syntax
