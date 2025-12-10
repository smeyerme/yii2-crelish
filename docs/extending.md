# Extending Crelish CMS

This guide explains how to extend Crelish CMS with custom functionality, plugins, and integrations.

## Extension Points

Crelish CMS provides several extension points:

1. **Custom Content Types**: Define new content types with custom fields and validation
2. **Custom Field Types**: Create new field types for content types
3. **Custom Controllers**: Add new controllers for custom functionality
4. **Custom API Endpoints**: Extend the API with new endpoints
5. **Custom Widgets**: Create widgets for the admin interface
6. **Custom Themes**: Create themes for the frontend
7. **Plugins**: Create reusable packages of functionality

## Creating Custom Content Types

Custom content types are defined in JSON files. See the [Content Types](./content-types.md) documentation for details.

## Creating Custom Field Types

### Field Type Structure

A custom field type consists of:

1. A PHP class that defines the field type behavior
2. View files for rendering the field in the admin interface
3. JavaScript for client-side validation and interaction

### Creating a Field Type Class

```php
<?php

namespace giantbits\crelish\fields;

use Yii;
use yii\helpers\Html;

class ColorPickerField extends BaseField
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        
        // Register assets
        ColorPickerAsset::register(Yii::$app->view);
    }
    
    /**
     * @inheritdoc
     */
    public function renderInput($model, $attribute, $options = []): string
    {
        $options = array_merge($this->options, $options);
        $options['class'] = 'color-picker-field';
        
        // Add data attributes for configuration
        $options['data-format'] = $this->format ?? 'hex';
        
        return Html::textInput($attribute, $model->$attribute, $options);
    }
    
    /**
     * @inheritdoc
     */
    public function renderView($model, $attribute, $options = []): string
    {
        $value = $model->$attribute;
        
        return Html::tag('div', '', [
            'class' => 'color-preview',
            'style' => "background-color: {$value}",
        ]) . Html::tag('span', $value, ['class' => 'color-value']);
    }
    
    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            ['value', 'string'],
            ['value', 'match', 'pattern' => '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
        ];
    }
}
```

### Creating Field Assets

```php
<?php

namespace giantbits\crelish\assets;

use yii\web\AssetBundle;

class ColorPickerAsset extends AssetBundle
{
    public $sourcePath = '@giantbits/crelish/assets/colorpicker';
    
    public $css = [
        'css/colorpicker.css',
    ];
    
    public $js = [
        'js/colorpicker.js',
    ];
    
    public $depends = [
        'yii\web\YiiAsset',
        'yii\bootstrap\BootstrapAsset',
    ];
}
```

### Registering a Custom Field Type

Register your custom field type in the application configuration:

```php
// config/web.php
return [
    // ... other configuration
    'components' => [
        'crelish' => [
            'class' => 'giantbits\crelish\components\CrelishComponent',
            'fieldTypes' => [
                'color' => 'giantbits\crelish\fields\ColorPickerField',
            ],
        ],
        // ... other components
    ],
];
```

### Using the Custom Field Type

Use your custom field type in a content type definition:

```json
{
  "name": "product",
  "label": "Product",
  "description": "Product content type",
  "fields": {
    "title": {
      "type": "string",
      "label": "Title",
      "required": true
    },
    "color": {
      "type": "color",
      "label": "Product Color",
      "description": "The primary color of the product",
      "format": "hex"
    }
  }
}
```

## Creating Custom Admin Controllers

### Extending CrelishBaseController

For custom admin pages that integrate seamlessly with the Crelish admin UI, extend `CrelishBaseController`:

```php
<?php

namespace app\workspace\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use Yii;
use yii\filters\AccessControl;

class ReportsController extends CrelishBaseController
{
    public $layout = 'crelish.twig';
    public $ctype = 'report';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'roles' => ['@']],
                ],
            ],
        ];
    }

    /**
     * Override setupHeaderBar for custom header components
     */
    protected function setupHeaderBar(): void
    {
        // Default left components
        $this->view->params['headerBarLeft'] = ['toggle-sidebar'];

        $action = $this->action?->id;

        switch ($action) {
            case 'index':
                $this->view->params['headerBarLeft'][] = ['title', Yii::t('crelish', 'Reports')];
                $this->view->params['headerBarLeft'][] = 'search';
                $this->view->params['headerBarRight'] = ['export'];
                break;

            case 'view':
                $this->view->params['headerBarLeft'][] = 'back-button';
                $this->view->params['headerBarLeft'][] = ['title', Yii::t('crelish', 'Report Details')];
                $this->view->params['headerBarRight'] = [
                    ['button', 'Download PDF', ['generate-pdf', 'id' => Yii::$app->request->get('id')], ['class' => 'btn btn-success']],
                ];
                break;

            case 'create':
            case 'update':
                $this->view->params['headerBarLeft'][] = 'back-button';
                $this->view->params['headerBarRight'] = [['save', true, $action === 'update']];
                break;
        }
    }

    public function getViewPath(): bool|string|null
    {
        return Yii::getAlias('@app/workspace/crelish/views/' . $this->id);
    }

    public function actionIndex(): string
    {
        // Use buildForm() for standard CRUD
        // Or implement custom logic
        return $this->render('index.twig', [
            'dataProvider' => $this->getDataProvider(),
        ]);
    }

    public function actionCreate()
    {
        // Use the built-in form builder
        $content = $this->buildForm();

        return $this->render('create.twig', [
            'content' => $content,
            'ctype' => $this->ctype,
        ]);
    }

    public function actionUpdate()
    {
        $content = $this->buildForm();

        return $this->render('update.twig', [
            'content' => $content,
            'ctype' => $this->ctype,
            'uuid' => $this->uuid,
        ]);
    }
}
```

**Key Features of CrelishBaseController:**

| Method | Purpose |
|--------|---------|
| `buildForm()` | Automatically generates forms from content type definitions |
| `setupHeaderBar()` | Configure the admin header (back button, save, delete, custom buttons) |
| `handleSessionAndQueryParams()` | Persist filter/search params across requests |
| `$this->uuid` | Automatically populated from query param |
| `$this->ctype` | Content type identifier |

### Header Bar Components

Available header bar components:

```php
// Left side
'toggle-sidebar'                          // Hamburger menu
'back-button'                             // Back navigation
['back-button', 'Custom Label', '/url']   // Custom back button
['title', 'Page Title']                   // Page title
'search'                                  // Search input

// Right side
'save'                                    // Save button
['save', true, true]                      // Save + Return + Delete buttons
['save', true, false]                     // Save + Return, no Delete
'delete'                                  // Delete button
'create'                                  // Create new button
'export'                                  // Export button
['button', 'Label', ['/route'], ['class' => 'btn btn-primary']]  // Custom button
```

### Sidebar Navigation

Add custom items to the admin sidebar by creating `workspace/crelish/sidebar.json`:

```json
{
  "items": [
    {
      "id": "reports",
      "label": "Reports",
      "url": "crelish/reports/index",
      "icon": "fa-sharp fa-regular fa-chart-bar",
      "order": 60
    },
    {
      "id": "partner",
      "label": "Partner",
      "url": "crelish/partner/index",
      "icon": "fa-sharp fa-regular fa-handshake",
      "order": 65
    },
    {
      "id": "settings-group",
      "label": "Settings",
      "icon": "fa-sharp fa-regular fa-cog",
      "order": 100,
      "children": [
        {
          "id": "general-settings",
          "label": "General",
          "url": "crelish/settings/general"
        },
        {
          "id": "email-settings",
          "label": "Email",
          "url": "crelish/settings/email"
        }
      ]
    }
  ]
}
```

**Sidebar Item Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `id` | string | Unique identifier |
| `label` | string | Display text |
| `url` | string | Route (relative to app root) |
| `icon` | string | FontAwesome icon class |
| `order` | int | Sort order (lower = higher) |
| `children` | array | Nested menu items |

### Directory Structure for Custom Admin Features

```
workspace/
├── crelish/
│   ├── controllers/
│   │   ├── ReportsController.php
│   │   ├── PartnerController.php
│   │   └── SettingsController.php
│   ├── views/
│   │   ├── reports/
│   │   │   ├── index.twig
│   │   │   ├── view.twig
│   │   │   └── create.twig
│   │   └── partner/
│   │       └── ...
│   └── sidebar.json
├── hooks/
│   └── ArticleHooks.php
├── models/
│   └── Report.php
└── widgets/
    └── PartnerManager/
        ├── PartnerManager.php
        ├── views/
        └── services/
```

### Simple Admin Controller Example

For basic functionality without extending CrelishBaseController:

```php
<?php

namespace giantbits\crelish\controllers;

use Yii;
use yii\web\Controller;

class ReportsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => 'yii\filters\AccessControl',
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['admin'],
                    ],
                ],
            ],
        ];
    }
    
    /**
     * Render the reports dashboard
     */
    public function actionIndex(): string
    {
        return $this->render('index');
    }
    
    /**
     * Generate a custom report
     */
    public function actionGenerate(): string
    {
        $type = Yii::$app->request->get('type');
        $startDate = Yii::$app->request->get('start_date');
        $endDate = Yii::$app->request->get('end_date');
        
        // Generate report data
        $data = $this->generateReportData($type, $startDate, $endDate);
        
        return $this->render('report', [
            'type' => $type,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'data' => $data,
        ]);
    }
    
    /**
     * Generate report data
     */
    private function generateReportData(string $type, string $startDate, string $endDate): array
    {
        // Implementation depends on the report type
        // ...
        
        return [];
    }
}
```

### API Controller

```php
<?php

namespace giantbits\crelish\modules\api\controllers;

use Yii;
use yii\filters\auth\CompositeAuth;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\auth\QueryParamAuth;

class CustomController extends BaseController
{
    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        
        // Add custom behaviors
        $behaviors['rateLimiter']['enableRateLimitHeaders'] = true;
        
        return $behaviors;
    }
    
    /**
     * Custom API endpoint
     */
    public function actionCustomEndpoint(): array
    {
        $param1 = Yii::$app->request->get('param1');
        $param2 = Yii::$app->request->get('param2');
        
        // Process the request
        $result = $this->processCustomRequest($param1, $param2);
        
        return $this->createResponse($result);
    }
    
    /**
     * Process custom request
     */
    private function processCustomRequest(string $param1, string $param2): array
    {
        // Implementation
        // ...
        
        return [
            'param1' => $param1,
            'param2' => $param2,
            'result' => 'success',
        ];
    }
}
```

### Registering Controllers

Register your custom controllers in the application configuration:

```php
// config/web.php
return [
    // ... other configuration
    'controllerMap' => [
        'reports' => 'giantbits\crelish\controllers\ReportsController',
    ],
    'modules' => [
        'api' => [
            'class' => 'giantbits\crelish\modules\api\Module',
            'controllerMap' => [
                'custom' => 'giantbits\crelish\modules\api\controllers\CustomController',
            ],
        ],
    ],
];
```

## Creating Frontend Widgets

Frontend widgets are powerful components that can handle complex multi-step workflows, forms, and business logic. They are placed in `workspace/widgets/`.

### Widget Directory Structure

```
workspace/widgets/
└── PartnerManager/
    ├── PartnerManager.php        # Main widget class
    ├── views/
    │   ├── _overview.twig        # Main view
    │   ├── _step1.twig           # Step views
    │   └── _step2.twig
    ├── services/                 # Business logic
    │   ├── PartnerMailService.php
    │   └── PartnerTypeService.php
    ├── strategies/               # Strategy pattern implementations
    │   └── StepManager.php
    └── styles/
        └── events.css
```

### Complex Widget Example

```php
<?php

namespace app\workspace\widgets\PartnerManager;

use Yii;
use yii\base\Widget;
use yii\web\View;

class PartnerManager extends Widget
{
    public $action;
    public $data;
    public $eventCode;

    private ?Event $eventData = null;
    private ?StepManager $stepManager = null;

    public function init()
    {
        parent::init();

        $this->loadEventData();

        if ($this->eventData) {
            $this->stepManager = new StepManager(new MailService($this->eventData));
        }

        $this->registerClientScripts();
    }

    public function run()
    {
        if (empty($this->eventData)) {
            return '';
        }

        $activeStep = $this->stepManager->getActiveStep();

        try {
            $result = $this->stepManager->processStep($activeStep, $this->eventData);

            // Handle redirect if returned
            if (isset($result['redirect'])) {
                Yii::$app->set('widgetResponse', $result['redirect']);
                return '';
            }

            return $this->render('_overview.twig', $result['templateVars']);
        } catch (\Exception $e) {
            Yii::error('Error in PartnerManager: ' . $e->getMessage(), __METHOD__);
            return $this->render('_error.twig', ['message' => $e->getMessage()]);
        }
    }

    private function registerClientScripts(): void
    {
        $css = file_get_contents(__DIR__ . '/styles/events.css');
        Yii::$app->view->registerCss($css);

        $js = "$(document).ready(function() { /* ... */ });";
        Yii::$app->view->registerJs($js, View::POS_READY);
    }

    private function loadEventData(): void
    {
        $eventCode = Yii::$app->request->get(0)[0] ?? null;
        if ($eventCode) {
            $this->eventData = Event::findOne(['code' => $eventCode]);
        }
    }
}
```

### Using Widgets in Templates

Widgets can be called from Twig templates:

```twig
{# In a page template #}
{% set widget = chelper.widget('app\\workspace\\widgets\\PartnerManager\\PartnerManager', {
    eventCode: page.eventCode,
    action: 'registration'
}) %}
{{ widget|raw }}
```

Or via the WidgetConnector field type in content types:

```json
{
  "widget": {
    "type": "widgetconnector",
    "label": "Widget",
    "availableWidgets": [
      "app\\workspace\\widgets\\PartnerManager\\PartnerManager",
      "app\\workspace\\widgets\\EventDisplay\\EventDisplay"
    ]
  }
}
```

### Widget Response Handling

Widgets can trigger redirects by setting a special response:

```php
// In widget
Yii::$app->set('widgetResponse', Yii::$app->response->redirect($url));
return '';

// The framework will detect this and perform the redirect
```

## Creating Dashboard Widgets

Dashboard widgets extend `CrelishDashboardWidget` and appear in the analytics dashboard:

### Widget Class

```php
<?php

namespace giantbits\crelish\widgets;

use Yii;
use yii\base\Widget;
use yii\helpers\Html;

class StatisticsWidget extends Widget
{
    /**
     * @var string Widget title
     */
    public string $title = 'Statistics';
    
    /**
     * @var string Time period (daily, weekly, monthly)
     */
    public string $period = 'daily';
    
    /**
     * @var array Data for the widget
     */
    public array $data = [];
    
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        
        if (empty($this->data)) {
            $this->data = $this->fetchData();
        }
        
        // Register assets
        StatisticsWidgetAsset::register($this->view);
    }
    
    /**
     * @inheritdoc
     */
    public function run(): string
    {
        return $this->render('statistics', [
            'title' => $this->title,
            'period' => $this->period,
            'data' => $this->data,
        ]);
    }
    
    /**
     * Fetch data for the widget
     */
    protected function fetchData(): array
    {
        // Implementation depends on the widget
        // ...
        
        return [];
    }
}
```

### Widget View

```php
<?php
/**
 * @var string $title
 * @var string $period
 * @var array $data
 */
?>

<div class="statistics-widget">
    <div class="widget-header">
        <h3><?= $title ?></h3>
        <div class="widget-controls">
            <select class="period-selector">
                <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily</option>
                <option value="weekly" <?= $period === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
            </select>
        </div>
    </div>
    <div class="widget-body">
        <div class="statistics-chart" data-period="<?= $period ?>" data-chart="<?= htmlspecialchars(json_encode($data)) ?>"></div>
    </div>
</div>
```

### Using the Widget

```php
<?= \giantbits\crelish\widgets\StatisticsWidget::widget([
    'title' => 'Content Statistics',
    'period' => 'weekly',
]) ?>
```

## Creating Plugins

### Plugin Structure

A plugin is a package that can be installed via Composer. It should have the following structure:

```
my-plugin/
├── composer.json
├── Plugin.php
├── controllers/
├── models/
├── views/
├── widgets/
├── assets/
└── config/
```

### Plugin Class

```php
<?php

namespace vendor\my-plugin;

use Yii;
use yii\base\BootstrapInterface;

class Plugin implements BootstrapInterface
{
    /**
     * @inheritdoc
     */
    public function bootstrap($app): void
    {
        // Register components
        $app->setComponents([
            'myPluginComponent' => [
                'class' => 'vendor\my-plugin\components\MyPluginComponent',
            ],
        ]);
        
        // Register controllers
        $app->controllerMap['my-plugin'] = [
            'class' => 'vendor\my-plugin\controllers\DefaultController',
        ];
        
        // Register API controllers
        if ($app->hasModule('api')) {
            $app->getModule('api')->controllerMap['my-plugin'] = [
                'class' => 'vendor\my-plugin\controllers\ApiController',
            ];
        }
        
        // Register URL rules
        $app->getUrlManager()->addRules([
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'my-plugin/<action:[\w\-]+>',
                'route' => 'my-plugin/<action>',
            ],
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'api/my-plugin/<action:[\w\-]+>',
                'route' => 'api/my-plugin/<action>',
            ],
        ], false);
        
        // Register event handlers
        $app->on('afterLogin', ['vendor\my-plugin\components\EventHandler', 'handleAfterLogin']);
    }
}
```

### Composer Configuration

```json
{
    "name": "vendor/my-plugin",
    "description": "My Plugin for Crelish CMS",
    "type": "crelish-plugin",
    "require": {
        "giantbits/yii2-crelish": "^0.8.0"
    },
    "autoload": {
        "psr-4": {
            "vendor\\my-plugin\\": ""
        }
    },
    "extra": {
        "bootstrap": "vendor\\my-plugin\\Plugin"
    }
}
```

### Installing a Plugin

Install the plugin via Composer:

```bash
composer require vendor/my-plugin
```

## Hooks and Events

Crelish CMS provides several ways to hook into the content lifecycle.

### Convention-Based Content Type Hooks

The simplest way to add hooks is using the convention-based system. Create a hooks class in `workspace/hooks/` named after your content type:

```
workspace/hooks/
├── ArticleHooks.php
├── PageHooks.php
├── ProductHooks.php
└── UserHooks.php
```

**Available Hook Methods:**

| Method | When Called | Use Case |
|--------|-------------|----------|
| `afterSave($params)` | After content is saved successfully | Send notifications, update caches, sync external systems |
| `beforeDelete($params)` | Before content is deleted | Validate deletion, clean up related data |
| `afterDelete($params)` | After content is deleted | Log deletion, notify users, cleanup |

**Example: ArticleHooks.php**

```php
<?php

namespace app\workspace\hooks;

use Yii;
use yii\base\Component;

class ArticleHooks extends Component
{
    /**
     * Called after an article is saved
     * @param array $params Contains 'data' key with the model instance
     */
    public static function afterSave($params)
    {
        $model = $params['data'];

        // Skip if not published
        if ($model->state != 2) {
            return;
        }

        // Clear article cache
        Yii::$app->cache->delete('article_list');

        // Notify subscribers
        if ($model->isNewRecord) {
            self::notifySubscribers($model);
        }

        // Sync to external CRM
        self::syncToCrm($model);
    }

    /**
     * Called before an article is deleted
     */
    public static function beforeDelete($params)
    {
        $model = $params['data'];

        // Log the deletion
        Yii::info("Article '{$model->title}' (UUID: {$model->uuid}) is being deleted", 'content.delete');

        // Clean up related comments
        \app\workspace\models\Comment::deleteAll(['article_uuid' => $model->uuid]);
    }

    /**
     * Called after an article is deleted
     */
    public static function afterDelete($params)
    {
        $model = $params['data'];

        // Clear caches
        Yii::$app->cache->delete('article_list');
        Yii::$app->cache->delete('article_' . $model->uuid);

        // Remove from search index
        Yii::$app->search->remove('article', $model->uuid);
    }

    private static function notifySubscribers($model)
    {
        // Implementation
    }

    private static function syncToCrm($model)
    {
        // Implementation
    }
}
```

The hooks are automatically discovered - no configuration needed. Just create the file following the naming convention `{ContentType}Hooks.php`.

### Yii2 Event System

For more advanced use cases, you can also use Yii2's event system:

```php
// Listen for content creation
Yii::$app->on('afterContentCreate', function($event) {
    $contentType = $event->sender->contentType;
    $contentItem = $event->sender->contentItem;

    // Do something with the new content item
});

// Listen for content update
Yii::$app->on('afterContentUpdate', function($event) {
    $contentType = $event->sender->contentType;
    $contentItem = $event->sender->contentItem;
    $oldContentItem = $event->sender->oldContentItem;

    // Do something with the updated content item
});

// Listen for content deletion
Yii::$app->on('afterContentDelete', function($event) {
    $contentType = $event->sender->contentType;
    $contentItemId = $event->sender->contentItemId;

    // Do something after content deletion
});
```

### User Events

```php
// Listen for user login
Yii::$app->on('afterLogin', function($event) {
    $user = $event->identity;
    
    // Do something after user login
});

// Listen for user registration
Yii::$app->on('afterRegister', function($event) {
    $user = $event->sender->user;
    
    // Do something after user registration
});
```

### API Events

```php
// Listen for API requests
Yii::$app->on('beforeApiRequest', function($event) {
    $controller = $event->sender;
    $action = $event->action;
    
    // Do something before API request processing
});

// Listen for API responses
Yii::$app->on('afterApiResponse', function($event) {
    $controller = $event->sender;
    $action = $event->action;
    $response = $event->response;
    
    // Modify the API response
    $response->data = array_merge($response->data, [
        'extra' => 'data',
    ]);
});
```

## Best Practices

1. **Follow Yii2 conventions**: Adhere to Yii2 coding standards and conventions.

2. **Use namespaces**: Organize your code with proper namespaces to avoid conflicts.

3. **Document your code**: Add PHPDoc comments to classes and methods.

4. **Write tests**: Create unit and integration tests for your extensions.

5. **Use dependency injection**: Avoid hard-coding dependencies; use dependency injection instead.

6. **Handle errors gracefully**: Implement proper error handling and logging.

7. **Respect backward compatibility**: Avoid breaking changes when updating your extensions.

8. **Use events for loose coupling**: Use events to allow other extensions to interact with yours.

9. **Optimize performance**: Be mindful of performance implications, especially for frequently used code.

10. **Secure your code**: Follow security best practices to prevent vulnerabilities. 