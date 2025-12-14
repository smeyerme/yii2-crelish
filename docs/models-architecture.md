# Crelish CMS - Model Architecture Guide

This document provides a comprehensive overview of all model-related classes in Crelish CMS, their purposes, and when to use each one.

## Architecture Overview

```
                    ┌─────────────────────────────────────────┐
                    │           Element Definition            │
                    │      (workspace/elements/*.json)        │
                    └─────────────────┬───────────────────────┘
                                      │
                    ┌─────────────────▼───────────────────────┐
                    │         CrelishStorageFactory           │
                    │   (determines storage type from JSON)   │
                    └─────────────────┬───────────────────────┘
                                      │
              ┌───────────────────────┴───────────────────────┐
              │                                               │
    ┌─────────▼─────────┐                         ┌──────────▼──────────┐
    │  CrelishDbStorage │                         │  CrelishJsonStorage │
    │   (storage: db)   │                         │  (storage: json)    │
    └─────────┬─────────┘                         └──────────┬──────────┘
              │                                               │
              │ uses                                          │ uses
              ▼                                               ▼
    ┌─────────────────────┐                       ┌──────────────────────┐
    │ CrelishModelResolver│                       │  workspace/data/     │
    │ (maps ctype → class)│                       │  {ctype}/{uuid}.json │
    └─────────┬───────────┘                       └──────────────────────┘
              │
              ▼
    ┌─────────────────────┐
    │  workspace/models/  │
    │  {Ctype}.php        │
    │  (ActiveRecord)     │
    └─────────────────────┘
```

## Core Classes

### 1. CrelishDynamicModel

**Purpose:** Main model class for the CMS admin interface (backend).

**Location:** `components/CrelishDynamicModel.php`

**Extends:** `yii\base\DynamicModel`

**Use when:** Editing content in the CMS backend.

**Key features:**
- Loads field definitions from element JSON files
- Dynamically creates attributes based on element definition
- Handles form validation rules
- Supports i18n translations
- Automatic save/delete via StorageFactory

**Example:**
```php
// In CMS backend (ContentController)
$model = new CrelishDynamicModel([
    'ctype' => 'services',
    'uuid' => $uuid  // Optional - omit for new records
]);

// Access field definitions
$fields = $model->fieldDefinitions;

// Access attributes
echo $model->systitle;

// Access translations
echo $model->i18n['fr']['systitle'];

// Save
$model->attributes = $_POST['CrelishDynamicModel'];
$model->save();
```

---

### 2. CrelishDataManager

**Purpose:** Unified data access layer for querying content.

**Location:** `components/CrelishDataManager.php`

**Extends:** `yii\base\Component`

**Use when:** Querying content in frontend widgets, templates, or anywhere you need to list/filter content.

**Key features:**
- Storage-agnostic querying (works with both DB and JSON storage)
- Filtering, sorting, pagination
- Returns DataProviders for use with GridView/ListView
- Supports relations (for DB storage)

**Example:**
```php
// Get all published services
$dm = new CrelishDataManager('services', [
    'filter' => ['state' => ['strict', 2]],
    'sort' => ['systitle' => SORT_ASC],
    'pageSize' => 10
]);

// Get raw data (array)
$services = $dm->rawAll();

// Get DataProvider for GridView
$provider = $dm->getProvider();

// Get single record
$dm = new CrelishDataManager('services', [], $uuid);
$service = $dm->one();

// With relations (DB storage only)
$dm = new CrelishDataManager('services', $settings, null, true); // autoSetRelations
$services = $dm->rawAll(true); // withRelations
```

---

### 3. CrelishModelResolver

**Purpose:** Maps content type identifiers (ctype) to PHP model class names.

**Location:** `components/CrelishModelResolver.php`

**Use when:** You need to get the model class for a ctype programmatically.

**Key features:**
- Auto-discovers models from `workspace/models/` directory
- Caches mapping in production for performance
- Supports models with explicit `$ctype` property
- Backwards compatible with legacy `ucfirst()` naming

**Example:**
```php
// Get model class for a ctype
$modelClass = CrelishModelResolver::getModelClass('services');
// Returns: 'app\workspace\models\Services'

// Check if model exists
if (CrelishModelResolver::modelExists('services')) {
    // ...
}

// Get all registered models
$allModels = CrelishModelResolver::getAllModels();
```

---

### 4. CrelishStorageFactory

**Purpose:** Factory that creates the appropriate storage implementation.

**Location:** `components/CrelishStorageFactory.php`

**Use when:** Internally used by other classes. Rarely called directly.

**How it works:**
- Reads the element definition JSON
- Checks the `storage` property (`"db"` or `"json"`)
- Returns `CrelishDbStorage` or `CrelishJsonStorage`

---

### 5. CrelishDataStorage (Interface)

**Purpose:** Contract that all storage implementations must follow.

**Location:** `components/CrelishDataStorage.php`

**Methods:**
- `findOne(string $ctype, string $uuid): ?array`
- `findAll(string $ctype, array $filter = [], array $sort = []): array`
- `getDataProvider(string $ctype, ...): DataProviderInterface`
- `save(string $ctype, array $data): bool`
- `delete(string $ctype, string $uuid): bool`
- `createQuery(string $ctype): Query`

---

### 6. CrelishDbStorage

**Purpose:** Storage implementation for database-backed content.

**Location:** `components/CrelishDbStorage.php`

**Implements:** `CrelishDataStorage`

**Requirements:**
- Element definition must have `"storage": "db"`
- Corresponding database table must exist
- Model class in `workspace/models/`

**Features:**
- Full ActiveRecord support
- Relations
- ActiveDataProvider for efficient pagination
- Freesearch across text columns

---

### 7. CrelishJsonStorage

**Purpose:** Storage implementation for JSON file-backed content.

**Location:** `components/CrelishJsonStorage.php`

**Implements:** `CrelishDataStorage`

**Requirements:**
- Element definition has `"storage": "json"` (or no storage property - default)

**Features:**
- Stores each record as `workspace/data/{ctype}/{uuid}.json`
- Good for small datasets, simple content
- No database required

---

### 8. CrelishJsonModel

**Purpose:** Base class for custom ActiveRecord models with JSON field support.

**Location:** `components/CrelishJsonModel.php`

**Extends:** `yii\db\ActiveRecord`

**Use when:** You need a proper ActiveRecord model with:
- Relations to other models
- JSON column support (storing extra fields in a JSON column)
- Custom business logic

**Example:**
```php
// workspace/models/Services.php
class Services extends CrelishJsonModel
{
    public $ctype = 'services';
    public static $key = 'services'; // Links to element definition

    public static function tableName()
    {
        return 'services';
    }

    // Define relation
    public function getCategory()
    {
        return $this->hasOne(ServiceCategory::class, ['uuid' => 'category']);
    }
}
```

---

### 9. CrelishActiveRecord

**Purpose:** Base class for ActiveRecord models with translation behavior.

**Location:** `components/CrelishActiveRecord.php`

**Extends:** `yii\db\ActiveRecord`

**Use when:** You need translation support via the `crelish_translation` table.

**Features:**
- Attaches `CrelishTranslationBehavior` automatically
- Handles loading/saving translations
- Supports `loadAllTranslations()` method

---

## Deprecated Classes (Do Not Use)

### CrelishDynamicJsonModel
**Status:** DEPRECATED - use `CrelishDynamicModel`
**Reason:** Old version without i18n support. Now just wraps StorageFactory.

### CrelishDataProvider
**Status:** DEPRECATED - use `CrelishDataManager`
**Reason:** Old API. Now internally wraps CrelishDataManager for backwards compatibility.

### CrelishJsonDataProvider
**Status:** DEPRECATED - use `CrelishDataManager`
**Reason:** Replaced by unified CrelishDataManager.

### CrelishDataResolver
**Status:** PARTIALLY DEPRECATED
**Reason:** Functionality moved to CrelishDataManager and CrelishModelResolver.

---

## Usage Patterns

### Backend (CMS Admin)

The CMS admin uses `CrelishDynamicModel` internally through `CrelishBaseController::buildForm()`:

```php
// ContentController automatically uses CrelishDynamicModel
public function actionUpdate($ctype, $uuid)
{
    $this->ctype = $ctype;
    $this->uuid = $uuid;

    return $this->render('update.twig', [
        'form' => $this->buildForm('update')
    ]);
}
```

### Frontend - Querying Data in Widgets

```php
// In a widget (e.g., ServicesList)
class ServicesList extends Widget
{
    public function run()
    {
        $dm = new CrelishDataManager('services', [
            'filter' => ['state' => ['strict', 2]],
            'sort' => ['systitle' => SORT_ASC]
        ]);

        $services = $dm->rawAll();

        return $this->render('services.twig', [
            'services' => $services
        ]);
    }
}
```

### Frontend - Querying in Twig Templates

Use the `crelish_content()` Twig function:

```twig
{% set services = crelish_content('services', {
    filter: { state: ['strict', 2] },
    sort: { systitle: 4 }
}) %}

{% for service in services %}
    <h2>{{ service.systitle }}</h2>
{% endfor %}
```

### Working with Relations (DB Storage)

```php
// Model with relation
class Services extends CrelishActiveRecord
{
    public function getCategory()
    {
        return $this->hasOne(ServiceCategory::class, ['uuid' => 'category']);
    }
}

// Query with relations
$dm = new CrelishDataManager('services', [], null, true);
$query = $dm->getQuery();
$dm->setRelations($query);
$services = $query->with('category')->all();
```

### Single Record Access

```php
// Option 1: CrelishDataManager (storage-agnostic)
$dm = new CrelishDataManager('services', [], $uuid);
$data = $dm->one(); // Returns array

// Option 2: Direct ActiveRecord (DB storage only)
$service = Services::findOne($uuid);

// Option 3: CrelishDynamicModel (for form editing)
$model = new CrelishDynamicModel(['ctype' => 'services', 'uuid' => $uuid]);
```

---

## Element Definition Properties

```json
{
  "label": "Services",
  "key": "services",
  "storage": "db",           // "db" or "json" (default: "json")
  "usePublishingMeta": true, // Add from/to date fields
  "sortDefault": {
    "systitle": "SORT_ASC"
  },
  "fields": [
    {
      "key": "systitle",
      "label": "Title",
      "type": "textInput",
      "translatable": true,   // Enable i18n for this field
      "visibleInGrid": true,
      "sortable": true,
      "rules": [["required"]]
    }
  ]
}
```

---

## Summary Table

| Class | Purpose | Use In |
|-------|---------|--------|
| `CrelishDynamicModel` | Form model for CMS admin | Backend only |
| `CrelishDataManager` | Query/list content | Frontend widgets, templates |
| `CrelishModelResolver` | Get model class from ctype | Internal, advanced use |
| `CrelishDbStorage` | DB storage implementation | Internal |
| `CrelishJsonStorage` | JSON file storage | Internal |
| `CrelishJsonModel` | ActiveRecord + JSON fields | Custom models with relations |
| `CrelishActiveRecord` | ActiveRecord + translations | Custom models with i18n |
| `CrelishStorageFactory` | Create storage instance | Internal |

---

## Migration Guide

### From CrelishDataProvider to CrelishDataManager

```php
// Old (deprecated)
$dp = new CrelishDataProvider('services', ['filter' => $filter]);
$all = $dp->rawAll();

// New
$dm = new CrelishDataManager('services', ['filter' => $filter]);
$all = $dm->rawAll();
```

### From CrelishDynamicJsonModel to CrelishDynamicModel

```php
// Old (deprecated)
$model = new CrelishDynamicJsonModel([], ['ctype' => 'services', 'uuid' => $uuid]);

// New
$model = new CrelishDynamicModel(['ctype' => 'services', 'uuid' => $uuid]);
```