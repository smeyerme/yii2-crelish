# Crelish CMS - Developer Documentation

This document describes how the Crelish CMS (Yii2-based) handles models, element definitions, forms, relations, and translations. Use this as a reference when creating new content types.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Naming Conventions](#naming-conventions)
3. [Creating a New Content Type](#creating-a-new-content-type)
4. [Element Definition (JSON)](#element-definition-json)
5. [Model Class (PHP)](#model-class-php)
6. [Relations](#relations)
   - [Auto-Generated Relations](#auto-generated-relations-recommended)
   - [Manual Relations](#defining-a-relation-element-json)
7. [Translations](#translations)
8. [Field Types](#field-types)
9. [Storage Options](#storage-options)
10. [Complete Examples](#complete-examples)
11. [IDE Helpers](#ide-helpers)
12. [Migration Generator](#migration-generator)

---

## Architecture Overview

Crelish CMS uses a dual-file system for each content type:

1. **Element Definition** (`workspace/elements/{ctype}.json`) - Defines fields, validation rules, form layout
2. **Model Class** (`workspace/models/{Ctype}.php`) - PHP ActiveRecord with relations and custom logic

The system automatically:
- Generates admin forms based on JSON definitions
- Handles CRUD operations via `CrelishDataManager`
- Resolves relations between content types
- Manages storage (DB or JSON files)

### Key Components

| Component | Location | Purpose |
|-----------|----------|---------|
| `CrelishDynamicModel` | `yii2-crelish/components/` | Dynamic model for form handling |
| `CrelishDataManager` | `yii2-crelish/components/` | Unified data access layer |
| `CrelishDbStorage` | `yii2-crelish/components/` | Database storage implementation |
| `CrelishJsonStorage` | `yii2-crelish/components/` | JSON file storage implementation |
| `CrelishStorageFactory` | `yii2-crelish/components/` | Determines storage type from definition |
| `CrelishBaseController` | `yii2-crelish/components/` | Form building and rendering |
| `CrelishTranslationBehavior` | `yii2-crelish/components/` | Handles field translations |
| `CrelishModelResolver` | `yii2-crelish/components/` | Auto-discovers model classes by $ctype |
| `CrelishAutoRelationsTrait` | `yii2-crelish/components/` | Auto-generates relations from JSON |
| `IdeHelperController` | `yii2-crelish/commands/` | Generates PHPDoc annotations for IDE support |
| `MigrationController` | `yii2-crelish/commands/` | Generates database migrations from JSON |

---

## Naming Conventions

### Flexible Class Naming (Auto-Discovery)

The system uses `CrelishModelResolver` to automatically discover model classes based on their `$ctype` property. This allows you to use proper PSR-4 naming conventions.

### How It Works

1. You define `public $ctype = 'eventcategory';` in your model
2. The system scans all models and builds a mapping
3. Your class can be named anything (e.g., `EventCategory` instead of `Eventcategory`)

### Naming Pattern

| What | Format | Example |
|------|--------|---------|
| Content Type Key (`ctype`) | lowercase, no spaces/hyphens | `event`, `eventcategory`, `news` |
| Element Definition File | `{ctype}.json` | `event.json`, `eventcategory.json` |
| Model Class Name | **Any valid class name** | `Event`, `EventCategory`, `BlogPost` |
| Model File Name | `{ClassName}.php` | `Event.php`, `EventCategory.php` |
| Database Table Name | Usually `{ctype}` | `event`, `eventcategory` |
| Namespace | `app\workspace\models` | Always this namespace |

### The Key: `$ctype` Property

The **only requirement** is that your model class has a `public $ctype` property that matches the JSON definition's `key`:

```php
// workspace/models/EventCategory.php - PSR-4 naming!
class EventCategory extends \yii\db\ActiveRecord
{
    public $ctype = 'eventcategory';  // This links to eventcategory.json

    public static function tableName(): string
    {
        return 'eventcategory';  // Table name can be anything
    }
}
```

```json
// workspace/elements/eventcategory.json
{
  "key": "eventcategory",  // Must match $ctype in the model
  "storage": "db",
  ...
}
```

### Examples

**New Way (Recommended):**
```
ctype: "eventcategory"
├── Element: workspace/elements/eventcategory.json (key: "eventcategory")
├── Model:   workspace/models/EventCategory.php
├── Class:   EventCategory (proper PSR-4 naming!)
├── $ctype:  'eventcategory' (links class to JSON)
└── Table:   eventcategory (or any name you choose)
```

**Legacy Way (Still Works):**
```
ctype: "eventcategory"
├── Element: workspace/elements/eventcategory.json
├── Model:   workspace/models/Eventcategory.php
├── Class:   Eventcategory (ucfirst convention)
└── Table:   eventcategory
```

### IMPORTANT: ctype Must Be Lowercase

```php
// CORRECT ctype values
$ctype = 'eventcategory';  // lowercase, no separation
$ctype = 'blogpost';       // lowercase, no separation
$ctype = 'news';           // simple lowercase

// WRONG - These won't work!
$ctype = 'eventCategory';  // No CamelCase in ctype
$ctype = 'event_category'; // No underscores in ctype
$ctype = 'event-category'; // No hyphens in ctype
```

### How the System Resolves Class Names

```php
// CrelishModelResolver scans all models and reads their $ctype property
// It builds a map: ['eventcategory' => 'app\workspace\models\EventCategory']

// Usage:
$modelClass = CrelishModelResolver::getModelClass('eventcategory');
// Returns: 'app\workspace\models\EventCategory'

// Check if model exists:
if (CrelishModelResolver::modelExists('eventcategory')) {
    // ...
}
```

### Backwards Compatibility

If no model with matching `$ctype` is found, the system falls back to the legacy `ucfirst()` convention:

```php
// If no model has $ctype = 'eventcategory', it tries:
// app\workspace\models\Eventcategory (ucfirst fallback)
```

This means **all existing projects continue to work** without any changes.

---

## Creating a New Content Type

### Step 1: Create the Element Definition

Create `workspace/elements/{ctype}.json`:

```json
{
  "key": "{ctype}",
  "storage": "db",
  "label": "Human Readable Name",
  "category": "Category for Admin Menu",
  "tabs": [...],
  "fields": [...],
  "sortDefault": {...}
}
```

### Step 2: Create the Model Class

Create `workspace/models/{Ctype}.php`:

```php
<?php

namespace app\workspace\models;

class {Ctype} extends \yii\db\ActiveRecord
{
    public $ctype = '{ctype}';

    public static function tableName(): string
    {
        return '{ctype}';
    }
}
```

### Step 3: Create the Database Table

Create a migration or SQL:

```sql
CREATE TABLE `{ctype}` (
    `uuid` VARCHAR(36) NOT NULL PRIMARY KEY,
    `systitle` VARCHAR(256),
    `state` INT(11) DEFAULT 0,
    `created` INT(11),
    `updated` INT(11),
    -- Add your custom fields here
);
```

---

## Element Definition (JSON)

### Basic Structure

```json
{
  "key": "example",
  "storage": "db",
  "label": "Examples",
  "category": "Content",
  "selectable": true,
  "usePublishingMeta": false,
  "tabs": [
    {
      "label": "Content",
      "key": "content",
      "groups": [
        {
          "label": "Main Content",
          "key": "main",
          "settings": {
            "width": 8,
            "showLabel": true
          },
          "fields": ["systitle", "description"]
        },
        {
          "label": "Settings",
          "key": "settings",
          "settings": {
            "width": 4
          },
          "fields": ["state", "created"]
        }
      ]
    }
  ],
  "fields": [...],
  "sortDefault": {
    "created": "SORT_DESC"
  }
}
```

### Root Properties

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `key` | string | Yes | Content type identifier (lowercase) |
| `storage` | string | No | `"db"` or `"json"` (default: `"json"`) |
| `label` | string | Yes | Human-readable name for admin |
| `category` | string | No | Admin menu category |
| `selectable` | boolean | No | Can be selected in relationSelect fields |
| `usePublishingMeta` | boolean | No | Adds `from`/`to` publish date fields |
| `tabs` | array | Yes | Form tab definitions |
| `fields` | array | Yes | Field definitions |
| `sortDefault` | object | No | Default sorting for lists |

### Auto-Generated Fields

The system automatically adds these fields if not present:

- `uuid` - Unique identifier (string, 36 chars)
- `state` - Publishing state (integer: 0=Offline, 1=Draft, 2=Online, 3=Archived)
- `created` - Creation timestamp
- `updated` - Last update timestamp
- `from`/`to` - Publishing dates (if `usePublishingMeta: true`)

### Tab/Group Structure

```json
"tabs": [
  {
    "label": "Tab Label",
    "key": "tab_key",
    "visible": true,
    "groups": [
      {
        "label": "Group Label",
        "key": "group_key",
        "settings": {
          "width": 8,          // Bootstrap columns (1-12)
          "showLabel": true    // Show group header
        },
        "fields": ["field1", "field2"]
      }
    ]
  }
]
```

---

## Model Class (PHP)

### Minimal Model

```php
<?php

namespace app\workspace\models;

class Example extends \yii\db\ActiveRecord
{
    public $ctype = 'example';

    public static function tableName(): string
    {
        return 'example';
    }
}
```

### Model with Relations

```php
<?php

namespace app\workspace\models;

class Event extends \yii\db\ActiveRecord
{
    public $ctype = 'event';

    public static function tableName(): string
    {
        return 'event';
    }

    // Relation to another content type
    public function getCategory(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Eventcategory::class, ['uuid' => 'category']);
    }

    // Relation to Asset (for images/files)
    public function getLogoImage(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Asset::class, ['uuid' => 'logo']);
    }
}
```

### Model with Translation Support

```php
<?php

namespace app\workspace\models;

use giantbits\crelish\components\CrelishTranslationBehavior;

class Article extends \yii\db\ActiveRecord
{
    public $ctype = 'article';

    public static function tableName(): string
    {
        return 'article';
    }

    public function behaviors(): array
    {
        return [
            'translation' => [
                'class' => CrelishTranslationBehavior::class,
            ],
        ];
    }
}
```

### Model with Custom Logic

```php
<?php

namespace app\workspace\models;

class News extends \yii\db\ActiveRecord
{
    public string $ctype = 'news';
    public string $customUrl = '';

    public static function tableName(): string
    {
        return 'news';
    }

    // Relations
    public function getImageAsset(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Asset::class, ['uuid' => 'image']);
    }

    // Lifecycle hooks
    public function afterFind(): void
    {
        parent::afterFind();

        // Custom logic after loading
        $this->customUrl = '/news/' . $this->uuid;
    }

    public function beforeSave($insert): bool
    {
        if ($this->isNewRecord) {
            $this->created = time();
        }
        return parent::beforeSave($insert);
    }
}
```

---

## Relations

### Auto-Generated Relations (Recommended)

You can use `CrelishAutoRelationsTrait` to automatically generate relation methods from your JSON element definition. This eliminates the need to define relations twice (once in JSON, once in PHP).

**Usage:**

```php
<?php

namespace app\workspace\models;

use giantbits\crelish\components\CrelishAutoRelationsTrait;

class Event extends \yii\db\ActiveRecord
{
    use CrelishAutoRelationsTrait;

    public $ctype = 'event';

    public static function tableName(): string
    {
        return 'event';
    }

    // No need to manually define getCategory() - it's auto-generated from JSON!
    // You can still define explicit methods if you need custom logic.
}
```

**How it works:**

1. The trait reads `relationSelect` and `assetConnector` fields from your JSON definition
2. It auto-generates `hasOne`/`hasMany` relations based on the field config
3. Explicit methods in your model always take priority (backwards compatible)

**Auto-generated relation names:**

| JSON Field Type | Field Key | Auto-Generated Relations |
|----------------|-----------|-------------------------|
| `relationSelect` | `category` | `getCategory()`, `getEventcategory()` (by ctype) |
| `assetConnector` | `logo` | `getLogoAsset()`, `getLogoImage()` |
| `assetConnector` (multiple) | `slideshow` | `getSlideshowAsset()` (hasMany) |

**Example - accessing auto-relations:**

```php
$event = Event::findOne($uuid);

// These work automatically from JSON definition:
$category = $event->category;           // From relationSelect field
$slideshowAssets = $event->slideshowAsset;  // From assetConnector field (multiple)

// Eager loading works too:
$events = Event::find()->with(['category', 'slideshowAsset'])->all();
```

**When to use explicit methods instead:**

- When you need custom query logic (ordering, conditions)
- When the relation name doesn't match the auto-generated pattern
- When you need to override the relation behavior

```php
// Custom relation with specific ordering
public function getRecentComments(): \yii\db\ActiveQuery
{
    return $this->hasMany(Comment::class, ['event_uuid' => 'uuid'])
        ->orderBy(['created' => SORT_DESC])
        ->limit(10);
}
```

### Defining a Relation (Element JSON)

Use the `relationSelect` field type:

```json
{
  "label": "Category",
  "key": "category",
  "type": "relationSelect",
  "config": {
    "ctype": "eventcategory",
    "multiple": false,
    "filterFields": ["systitle"],
    "autocreate": false
  },
  "visibleInGrid": false,
  "rules": [["string", {"max": 256}]],
  "sortable": true
}
```

### Config Options for relationSelect

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `ctype` | string | Required | Related content type |
| `multiple` | boolean | `false` | Allow multiple selections |
| `filterFields` | array | `["systitle"]` | Fields to search on |
| `columns` | array | - | Columns to show in table (multiple mode) |
| `autocreate` | boolean | `false` | Auto-create related items |

### Defining a Relation (Model PHP)

```php
// Single relation (hasOne)
public function getCategory(): \yii\db\ActiveQuery
{
    // Pattern: ['uuid' => 'field_key_in_this_model']
    return $this->hasOne(Eventcategory::class, ['uuid' => 'category']);
}

// The relation name should match the ctype of the related model
// or be a descriptive name like getCategoryModel()
```

### Important Relation Rules

1. **The field key in JSON must match the column name in the database**
   ```json
   "key": "category"  // → Database column: category
   ```

2. **The relation method name follows Yii2 conventions**
   ```php
   // Method: getCategory() → Access via: $model->category
   // Method: getEventcategory() → Access via: $model->eventcategory
   ```

3. **Relations use UUID as the foreign key (not auto-increment ID)**
   ```php
   // The related table has uuid as primary key
   return $this->hasOne(RelatedModel::class, ['uuid' => 'field_key']);
   ```

4. **Data storage**
   - Single relation: Stores UUID string directly
   - Multiple relation: Stores JSON-encoded array of UUIDs

### Accessing Relations in Code

```php
// Get the related model
$event = Event::findOne($uuid);
$category = $event->category;  // Returns Eventcategory model or null

// Eager loading for performance
$events = Event::find()
    ->with('category')
    ->all();
```

---

## Translations

### Enabling Translations

1. **Add the behavior to your model:**

```php
public function behaviors(): array
{
    return [
        'translation' => [
            'class' => CrelishTranslationBehavior::class,
        ],
    ];
}
```

2. **Mark fields as translatable in JSON:**

```json
{
  "label": "Title",
  "key": "title",
  "type": "textInput",
  "translatable": true,
  "rules": [["required"], ["string", {"max": 256}]]
}
```

### How Translations Work

- Translations are stored in a separate `crelish_translation` table
- The behavior automatically loads translations for the current language
- Translations are saved with the pattern: `CrelishDynamicModel[i18n][{lang}][{field}]`

### Translation Table Structure

```sql
CREATE TABLE `crelish_translation` (
    `uuid` VARCHAR(36) NOT NULL PRIMARY KEY,
    `language` VARCHAR(5) NOT NULL,
    `source_model` VARCHAR(128) NOT NULL,
    `source_model_uuid` VARCHAR(36) NOT NULL,
    `source_model_attribute` VARCHAR(128) NOT NULL,
    `translation` LONGTEXT,
    INDEX (`source_model`, `source_model_uuid`, `language`)
);
```

### Configuring Languages

In your application config (`config/web.php` or `config/params.php`):

```php
'params' => [
    'crelish' => [
        'languages' => ['de', 'en', 'fr'],
    ],
],
```

---

## Field Types

### Standard Input Types

| Type | Description | Storage |
|------|-------------|---------|
| `textInput` | Single line text | VARCHAR |
| `textarea` | Multi-line text | TEXT |
| `passwordInput` | Password field | VARCHAR |
| `dropDownList` | Dropdown select | VARCHAR |
| `checkboxList` | Multiple checkboxes | JSON array |

### Special Crelish Types

| Type | Description | Usage |
|------|-------------|-------|
| `relationSelect` | Link to other content | See [Relations](#relations) |
| `assetConnector` | Image/file upload | Stores Asset UUID |
| `matrixConnector` | Flexible content blocks | Stores JSON |
| `jsonEditor` | JSON data editor | Stores JSON |

### Widget Types

Prefix with `widget_` to use Yii2/Kartik widgets:

```json
{
  "label": "Start Date",
  "key": "eventdateStart",
  "type": "widget_\\kartik\\widgets\\DateTimePicker",
  "transform": "datetime",
  "format": "datetime",
  "rules": [["required"]]
}
```

Common widgets:
- `widget_\\kartik\\widgets\\DatePicker`
- `widget_\\kartik\\widgets\\DateTimePicker`
- `widget_\\kartik\\color\\ColorInput`
- `widget_\\brussens\\yii2\\extensions\\trumbowyg\\TrumbowygWidget`

### Field Definition Properties

```json
{
  "label": "Field Label",
  "key": "field_key",
  "type": "textInput",
  "visibleInGrid": true,
  "visibleInFilter": true,
  "sortable": true,
  "translatable": false,
  "defaultValue": "default",
  "format": "text",
  "transform": null,
  "rules": [
    ["required"],
    ["string", {"max": 256}]
  ],
  "options": {},
  "widgetOptions": {},
  "inputOptions": {},
  "config": {}
}
```

| Property | Description |
|----------|-------------|
| `label` | Display label in form |
| `key` | Database column name |
| `type` | Field type (see above) |
| `visibleInGrid` | Show in list view |
| `visibleInFilter` | Include in search filters |
| `sortable` | Allow sorting by this field |
| `translatable` | Enable translations |
| `defaultValue` | Default value for new records |
| `format` | Display format (`text`, `date`, `datetime`) |
| `transform` | Data transformation (`date`, `datetime`, `json`, `state`) |
| `rules` | Yii2 validation rules |
| `options` | Field-specific options |
| `config` | Type-specific configuration |

### Validation Rules

```json
"rules": [
  ["required"],
  ["string", {"max": 256}],
  ["email"],
  ["integer"],
  ["safe"],
  ["number", {"min": 0, "max": 100}],
  ["url"],
  ["match", {"pattern": "/^[a-z]+$/"}]
]
```

---

## Storage Options

### Database Storage (`"storage": "db"`)

- Data stored in MySQL/MariaDB table
- Table name matches `ctype`
- Uses Yii2 ActiveRecord
- Supports relations and queries

### JSON Storage (`"storage": "json"`)

- Data stored in JSON files
- Location: `workspace/data/{ctype}/{uuid}.json`
- Good for simple content without relations
- No database required

### Choosing Storage

Use **Database** when:
- You need relations between content types
- You need complex queries
- You need transactions
- You have large amounts of data

Use **JSON** when:
- Simple content without relations
- No complex queries needed
- Content is rarely updated
- You don't want database overhead

---

## Complete Examples

### Example 1: Simple Content Type (Author)

**Element Definition: `workspace/elements/author.json`**

```json
{
  "key": "author",
  "storage": "db",
  "label": "Authors",
  "category": "Content",
  "selectable": true,
  "tabs": [
    {
      "label": "Info",
      "key": "info",
      "groups": [
        {
          "label": "Author Info",
          "key": "info",
          "settings": { "width": 8 },
          "fields": ["systitle", "bio", "avatar"]
        },
        {
          "label": "Settings",
          "key": "settings",
          "settings": { "width": 4 },
          "fields": ["email", "state", "created"]
        }
      ]
    }
  ],
  "fields": [
    {
      "label": "Name",
      "key": "systitle",
      "type": "textInput",
      "visibleInGrid": true,
      "sortable": true,
      "rules": [["required"], ["string", {"max": 128}]]
    },
    {
      "label": "Biography",
      "key": "bio",
      "type": "textarea",
      "rules": [["string", {"max": 2000}]]
    },
    {
      "label": "Email",
      "key": "email",
      "type": "textInput",
      "rules": [["email"], ["string", {"max": 256}]]
    },
    {
      "label": "Avatar",
      "key": "avatar",
      "type": "assetConnector",
      "rules": [["safe"]]
    }
  ],
  "sortDefault": {
    "systitle": "SORT_ASC"
  }
}
```

**Model: `workspace/models/Author.php`**

```php
<?php

namespace app\workspace\models;

class Author extends \yii\db\ActiveRecord
{
    public $ctype = 'author';

    public static function tableName(): string
    {
        return 'author';
    }

    public function getAvatarImage(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Asset::class, ['uuid' => 'avatar']);
    }
}
```

**Database Table:**

```sql
CREATE TABLE `author` (
    `uuid` VARCHAR(36) NOT NULL PRIMARY KEY,
    `systitle` VARCHAR(128),
    `bio` TEXT,
    `email` VARCHAR(256),
    `avatar` VARCHAR(36),
    `state` INT(11) DEFAULT 0,
    `created` INT(11),
    `updated` INT(11)
);
```

### Example 2: Content Type with Relations (Article)

**Element Definition: `workspace/elements/article.json`**

```json
{
  "key": "article",
  "storage": "db",
  "label": "Articles",
  "category": "Content",
  "tabs": [
    {
      "label": "Content",
      "key": "content",
      "groups": [
        {
          "label": "Content",
          "key": "content",
          "settings": { "width": 8 },
          "fields": ["systitle", "teaser", "body", "image"]
        },
        {
          "label": "Settings",
          "key": "settings",
          "settings": { "width": 4 },
          "fields": ["author", "category", "tags", "state", "created"]
        }
      ]
    }
  ],
  "fields": [
    {
      "label": "Title",
      "key": "systitle",
      "type": "textInput",
      "visibleInGrid": true,
      "sortable": true,
      "rules": [["required"], ["string", {"max": 256}]]
    },
    {
      "label": "Teaser",
      "key": "teaser",
      "type": "textarea",
      "rules": [["required"], ["string", {"max": 500}]]
    },
    {
      "label": "Content",
      "key": "body",
      "type": "widget_\\brussens\\yii2\\extensions\\trumbowyg\\TrumbowygWidget",
      "rules": [["safe"]]
    },
    {
      "label": "Featured Image",
      "key": "image",
      "type": "assetConnector",
      "rules": [["safe"]]
    },
    {
      "label": "Author",
      "key": "author",
      "type": "relationSelect",
      "config": {
        "ctype": "author",
        "multiple": false
      },
      "rules": [["string", {"max": 36}]]
    },
    {
      "label": "Category",
      "key": "category",
      "type": "relationSelect",
      "config": {
        "ctype": "articlecategory"
      },
      "rules": [["string", {"max": 36}]]
    },
    {
      "label": "Tags",
      "key": "tags",
      "type": "relationSelect",
      "config": {
        "ctype": "tag",
        "multiple": true
      },
      "rules": [["safe"]]
    }
  ],
  "sortDefault": {
    "created": "SORT_DESC"
  }
}
```

**Model: `workspace/models/Article.php`**

```php
<?php

namespace app\workspace\models;

class Article extends \yii\db\ActiveRecord
{
    public $ctype = 'article';

    public static function tableName(): string
    {
        return 'article';
    }

    // Single relation to Author
    public function getAuthor(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Author::class, ['uuid' => 'author']);
    }

    // Single relation to Category
    public function getCategory(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Articlecategory::class, ['uuid' => 'category']);
    }

    // Relation to Asset for featured image
    public function getImageAsset(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Asset::class, ['uuid' => 'image']);
    }
}
```

### Example 3: Content Type with Translations

**Element Definition: `workspace/elements/product.json`**

```json
{
  "key": "product",
  "storage": "db",
  "label": "Products",
  "category": "Shop",
  "tabs": [
    {
      "label": "Info",
      "key": "info",
      "groups": [
        {
          "label": "Product Info",
          "key": "info",
          "settings": { "width": 8 },
          "fields": ["systitle", "description", "image"]
        },
        {
          "label": "Settings",
          "key": "settings",
          "settings": { "width": 4 },
          "fields": ["price", "sku", "state"]
        }
      ]
    }
  ],
  "fields": [
    {
      "label": "Product Name",
      "key": "systitle",
      "type": "textInput",
      "translatable": true,
      "visibleInGrid": true,
      "rules": [["required"], ["string", {"max": 256}]]
    },
    {
      "label": "Description",
      "key": "description",
      "type": "widget_\\brussens\\yii2\\extensions\\trumbowyg\\TrumbowygWidget",
      "translatable": true,
      "rules": [["safe"]]
    },
    {
      "label": "SKU",
      "key": "sku",
      "type": "textInput",
      "visibleInGrid": true,
      "rules": [["required"], ["string", {"max": 50}]]
    },
    {
      "label": "Price",
      "key": "price",
      "type": "textInput",
      "visibleInGrid": true,
      "rules": [["required"], ["number", {"min": 0}]]
    },
    {
      "label": "Image",
      "key": "image",
      "type": "assetConnector",
      "rules": [["safe"]]
    }
  ]
}
```

**Model: `workspace/models/Product.php`**

```php
<?php

namespace app\workspace\models;

use giantbits\crelish\components\CrelishTranslationBehavior;

class Product extends \yii\db\ActiveRecord
{
    public $ctype = 'product';

    public static function tableName(): string
    {
        return 'product';
    }

    public function behaviors(): array
    {
        return [
            'translation' => [
                'class' => CrelishTranslationBehavior::class,
            ],
        ];
    }

    public function getImageAsset(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Asset::class, ['uuid' => 'image']);
    }
}
```

---

## Quick Reference Checklist

When creating a new content type:

- [ ] Choose a lowercase, single-word `ctype` name (e.g., `blogpost`, not `blog-post`)
- [ ] Create `workspace/elements/{ctype}.json` with the exact `key` matching `ctype`
- [ ] Create `workspace/models/{ClassName}.php` (any valid PSR-4 class name)
- [ ] Set `public $ctype = '{ctype}';` in the model (links class to JSON)
- [ ] Set `return '{tablename}';` in `tableName()`
- [ ] Create database table with at least `uuid` column
- [ ] For relations: use `CrelishAutoRelationsTrait` (recommended) or add explicit methods
- [ ] For translations: add behavior to model and `"translatable": true` to fields

### Minimal Model Template (with Auto-Relations)

```php
<?php

namespace app\workspace\models;

use giantbits\crelish\components\CrelishAutoRelationsTrait;

class MyContentType extends \yii\db\ActiveRecord
{
    use CrelishAutoRelationsTrait;

    public $ctype = 'mycontenttype';

    public static function tableName(): string
    {
        return 'mycontenttype';
    }
}
```

### Common Mistakes to Avoid

1. **Wrong ctype format**: Use `eventcategory` not `event_category` or `eventCategory`
2. **Mismatched keys**: Element JSON `key` must match model `$ctype`
3. **Missing uuid column**: All tables need a `uuid` VARCHAR(36) primary key
4. **Wrong relation syntax**: Use `['uuid' => 'field_key']` not `['id' => 'field_key']`
5. **Forgetting the trait**: Use `CrelishAutoRelationsTrait` for auto-generated relations

---

## IDE Helpers

Crelish includes an IDE helper generator that creates PHPDoc annotations for better autocompletion in your IDE (PHPStorm, VS Code, etc.).

### Generating IDE Helpers

```bash
# Generate helpers for all models (creates _ide_helper_models.php)
php yii crelish/ide-helper/generate

# Generate helpers for a specific content type
php yii crelish/ide-helper/generate event

# Update model files directly with PHPDoc annotations
php yii crelish/ide-helper/generate --updateInPlace

# List all models with their properties and relations
php yii crelish/ide-helper/models
```

### What Gets Generated

The IDE helper generates:

1. **@property annotations** for all database fields from JSON definition
2. **@property-read annotations** for relations (both auto-generated and explicit)
3. **@method annotations** for common query methods (find, findOne, findAll)

### Example Output

After running `php yii crelish/ide-helper/generate --updateInPlace`, your Event model would look like:

```php
<?php

namespace app\workspace\models;

/**
 * Event model for event content type
 *
 * @property string $uuid Unique identifier
 * @property int $state Publishing state (0=Offline, 1=Draft, 2=Online, 3=Archived)
 * @property int|null $created Creation timestamp
 * @property int|null $updated Last update timestamp
 * @property string|null $systitle Titel
 * @property string|null $category Kategorie
 * @property string|null $eventdateStart Datum Start
 * @property string|null $eventdateEnd Datum Ende
 *
 * @property-read Eventcategory|null $category Related eventcategory
 * @property-read Asset[]|null $slideshowAsset Related asset records
 */
class Event extends \yii\db\ActiveRecord
{
    use CrelishAutoRelationsTrait;

    public $ctype = 'event';
    // ...
}
```

### IDE Helper File vs In-Place Updates

**IDE Helper File** (default):
- Creates `workspace/_ide_helper_models.php`
- Non-invasive, doesn't modify your models
- IDE reads this file for autocompletion
- Regenerate anytime without affecting code

**In-Place Updates** (`--updateInPlace`):
- Updates PHPDoc directly in model files
- Keeps documentation with the code
- More visible to developers reading the code
- Requires re-running when JSON definitions change

---

## Migration Generator

Crelish includes a migration generator that creates Yii2 database migrations from JSON element definitions. This eliminates the need to manually write migrations when creating new content types or modifying existing ones.

### Generating Migrations

```bash
# Create migration for a new content type table
php yii crelish/migration/create event

# Create migration for schema changes (compares JSON to database)
php yii crelish/migration/update event

# Create migrations for all content types missing tables
php yii crelish/migration/create-all

# Show diff between JSON definition and database schema
php yii crelish/migration/diff event

# Show status of all content types (table exists, needs update, etc.)
php yii crelish/migration/status
```

### How It Works

The migration generator:

1. **Reads JSON element definitions** from `workspace/elements/`
2. **Maps field types to database columns**:
   - `textInput` → `VARCHAR(max)` based on validation rules
   - `textarea` → `TEXT`
   - `numberInput` → `INT`
   - `relationSelect` → `VARCHAR(36)` (UUID) or `LONGTEXT` (multiple)
   - `assetConnector` → `VARCHAR(36)` (UUID) or `LONGTEXT` (multiple)
   - `jsonEditor`, `matrixConnector` → `LONGTEXT`
   - Fields with `transform: json` → `JSON`

3. **Generates standard columns** automatically:
   - `uuid` - Primary key (VARCHAR 36)
   - `state` - Publishing state (SMALLINT)
   - `created`, `updated` - Timestamps (INT)
   - `created_by`, `updated_by` - User UUIDs (VARCHAR 36)

4. **Creates indexes** for commonly queried columns (state, created)

### Example Generated Migration

Running `php yii crelish/migration/create blogpost` generates:

```php
<?php
use yii\db\Migration;

/**
 * Creates table for blogpost content type.
 *
 * Generated from JSON element definition.
 */
class m241129123456_create_blogpost_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%blogpost}}', [
            'uuid' => $this->string(36)->notNull(),
            'PRIMARY KEY ([[uuid]])',
            'created' => $this->integer()->null(),
            'updated' => $this->integer()->null(),
            'created_by' => $this->string(36)->null(),
            'updated_by' => $this->string(36)->null(),
            'state' => $this->smallInteger()->notNull()->defaultValue(1),
            'systitle' => $this->string(256)->null(),
            'content' => $this->text()->null(),
            'author' => $this->string(36)->null(),
            'category' => $this->string(36)->null(),
        ], 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Indexes for relation columns
        $this->createIndex('idx-blogpost-state', '{{%blogpost}}', 'state');
        $this->createIndex('idx-blogpost-created', '{{%blogpost}}', 'created');
    }

    public function safeDown()
    {
        $this->dropIndex('idx-blogpost-created', '{{%blogpost}}');
        $this->dropIndex('idx-blogpost-state', '{{%blogpost}}');
        $this->dropTable('{{%blogpost}}');
    }
}
```

### Checking Schema Status

Use `php yii crelish/migration/status` to see all content types:

```
Content Type Table Status
============================================================

event [OK]
eventcategory [OK]
news [NEEDS UPDATE - 2 changes]
blogpost [TABLE MISSING]
settings [JSON storage]
```

### Update Migrations

When you add fields to an existing JSON definition, use the `update` action:

```bash
php yii crelish/migration/update news
```

This compares the JSON definition to the current database schema and generates a migration with only the changes:

```php
public function safeUp()
{
    $this->addColumn('{{%news}}', 'featured', $this->smallInteger()->null()->defaultValue(0));
    $this->addColumn('{{%news}}', 'tags', 'LONGTEXT NULL');
}

public function safeDown()
{
    $this->dropColumn('{{%news}}', 'tags');
    $this->dropColumn('{{%news}}', 'featured');
}
```

### Options

| Option | Alias | Description |
|--------|-------|-------------|
| `--migrationsDir` | `-m` | Directory for migrations (default: `@app/migrations`) |
| `--migrationsNamespace` | `-n` | Namespace for migrations (optional) |
| `--useTablePrefix` | | Use Yii table prefix (default: true) |
