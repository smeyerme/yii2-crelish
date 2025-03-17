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

## Creating Custom Controllers

### Admin Controller

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

## Creating Custom Widgets

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

Crelish CMS provides several hooks and events that you can use to extend functionality:

### Content Events

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