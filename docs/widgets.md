# Working with Widgets in Crelish CMS

Widgets are reusable UI components that can be used to display content, implement user interactions, or provide specific functionality in your Crelish CMS. This guide explains how to use the built-in widgets and create custom widgets.

## Built-in Widgets

Crelish CMS comes with several built-in widgets that you can use out of the box.

### Core Widgets

| Widget | Description | Usage |
|--------|-------------|-------|
| HeaderBar | Main admin interface header bar | Used in layouts for navigation and actions |
| FlashMessages | Display notification messages | Alerts and status messages |
| ContentGrid | Display content items in a grid | Content listing pages |
| FormBuilder | Dynamically build forms | Content editing pages |
| AssetBrowser | Browse and select media assets | Media selection interfaces |
| ContentSelector | Select related content items | Content relationship fields |

### Using Built-in Widgets

To use a built-in widget in a Twig template:

```twig
{{ header_bar_widget({
    'leftComponents': ['toggle-sidebar', 'search'],
    'rightComponents': ['save', 'delete']
}) | raw }}
```

To use a widget in PHP code:

```php
<?php
use giantbits\crelish\components\widgets\HeaderBar;

echo HeaderBar::widget([
    'leftComponents' => ['toggle-sidebar', 'search'],
    'rightComponents' => ['save', 'delete'],
]);
```

## Creating Custom Widgets

You can create custom widgets to add new functionality to your Crelish CMS installation.

### Widget Structure

A basic widget consists of:

1. A widget class that extends `yii\base\Widget`
2. View files for rendering the widget
3. Optional asset bundles for CSS and JavaScript

### Creating a Basic Widget

Here's how to create a simple widget:

1. Create the widget class file in `components/widgets/MyWidget.php`:

```php
<?php

namespace app\components\widgets;

use yii\base\Widget;
use yii\helpers\Html;

class MyWidget extends Widget
{
    /**
     * @var string The widget title
     */
    public $title = 'My Widget';
    
    /**
     * @var array Data to display in the widget
     */
    public $data = [];
    
    /**
     * Initializes the widget
     */
    public function init()
    {
        parent::init();
        
        // Widget initialization code
        if (empty($this->data)) {
            $this->data = ['No data available'];
        }
    }
    
    /**
     * Renders the widget
     * @return string
     */
    public function run()
    {
        // Register assets if needed
        // MyWidgetAsset::register($this->view);
        
        // Render the widget view
        return $this->render('my-widget', [
            'title' => $this->title,
            'data' => $this->data,
        ]);
    }
}
```

2. Create the view file in `components/widgets/views/my-widget.php`:

```php
<div class="my-widget">
    <div class="my-widget-header">
        <h3><?= $title ?></h3>
    </div>
    <div class="my-widget-content">
        <ul>
            <?php foreach ($data as $item): ?>
                <li><?= $item ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
```

3. (Optional) Create an asset bundle for the widget in `components/widgets/assets/MyWidgetAsset.php`:

```php
<?php

namespace app\components\widgets\assets;

use yii\web\AssetBundle;

class MyWidgetAsset extends AssetBundle
{
    public $sourcePath = '@app/components/widgets/assets/my-widget';
    
    public $css = [
        'css/my-widget.css',
    ];
    
    public $js = [
        'js/my-widget.js',
    ];
    
    public $depends = [
        'yii\web\YiiAsset',
        'yii\bootstrap5\BootstrapAsset',
    ];
}
```

### Integrating with Twig

To make your widget available in Twig templates, you need to register it in your application configuration:

```php
// config/web.php
'components' => [
    'view' => [
        'class' => 'yii\web\View',
        'renderers' => [
            'twig' => [
                'class' => 'yii\twig\ViewRenderer',
                'extensions' => [
                    // ... other extensions
                ],
                'globals' => [
                    'my_widget' => ['app\components\widgets\MyWidget', 'widget'],
                ],
            ],
        ],
    ],
],
```

After registering, you can use your widget in Twig templates:

```twig
{{ my_widget({'title': 'Custom Title', 'data': ['Item 1', 'Item 2']}) | raw }}
```

## Advanced Widget Techniques

### AJAX-Enabled Widgets

You can create widgets that load content via AJAX:

```php
public function run()
{
    // Register JS for AJAX functionality
    $this->view->registerJs("
        $('#{$this->id}-load').on('click', function() {
            $.ajax({
                url: '{$this->ajaxUrl}',
                type: 'GET',
                success: function(data) {
                    $('#{$this->id}-content').html(data);
                }
            });
        });
    ");
    
    return $this->render('ajax-widget', [
        'id' => $this->id,
        'title' => $this->title,
    ]);
}
```

### Interactive Widgets

For widgets with user interaction:

1. Create a JavaScript file for your widget behavior
2. Register the file in your asset bundle
3. Implement controller actions for any backend interactions
4. Use data attributes to connect DOM elements to your JavaScript

Example JavaScript for an interactive widget:

```javascript
$(document).ready(function() {
    $('.my-widget-item').on('click', function() {
        const itemId = $(this).data('item-id');
        
        // Handle item click
        $.ajax({
            url: '/my-widget/get-item-details',
            data: {id: itemId},
            success: function(response) {
                $('#item-details').html(response);
            }
        });
    });
});
```

### Widget Events

You can define events for your widget to allow other code to react to widget actions:

```php
public function init()
{
    parent::init();
    
    // Define events
    $this->on(self::EVENT_BEFORE_RENDER, function($event) {
        // Code to execute before rendering
    });
    
    $this->on(self::EVENT_AFTER_RENDER, function($event) {
        // Code to execute after rendering
    });
}
```

## Widget Best Practices

1. **Keep Widgets Focused**: Each widget should have a single responsibility
2. **Make Widgets Configurable**: Use public properties to allow customization
3. **Handle Errors Gracefully**: Check for required data and provide fallbacks
4. **Optimize Asset Loading**: Only include the CSS and JavaScript that your widget needs
5. **Use Namespacing**: Prefix your CSS classes and JavaScript functions to avoid conflicts
6. **Document Your Widgets**: Provide clear documentation on how to use and configure your widgets

## Examples

### Data Visualization Widget

Here's an example of a widget that displays a chart:

```php
<?php

namespace app\components\widgets;

use yii\base\Widget;
use yii\helpers\Json;

class ChartWidget extends Widget
{
    public $title = 'Data Chart';
    public $type = 'bar'; // bar, line, pie, etc.
    public $data = [];
    public $options = [];
    
    public function run()
    {
        // Register Chart.js asset
        ChartAsset::register($this->view);
        
        // Prepare data for the chart
        $chartData = Json::encode($this->data);
        $chartOptions = Json::encode($this->options);
        
        // Register JS to initialize chart
        $this->view->registerJs("
            var ctx = document.getElementById('chart-{$this->id}').getContext('2d');
            var chart = new Chart(ctx, {
                type: '{$this->type}',
                data: {$chartData},
                options: {$chartOptions}
            });
        ");
        
        return $this->render('chart', [
            'id' => $this->id,
            'title' => $this->title,
        ]);
    }
}
```

## Debugging Widgets

When developing or troubleshooting widgets:

1. Enable debug mode in your configuration
2. Use `Yii::trace()` statements in your widget code
3. Check browser console for JavaScript errors
4. Use the Yii Debug Toolbar to inspect widget rendering
5. Test widgets in isolation before integrating them into your application

## See Also

- [Extending Crelish](./extending.md)
- [Frontend Integration](./frontend-integration.md)
- [API Documentation](./API.md)
- [Troubleshooting](./troubleshooting.md) 