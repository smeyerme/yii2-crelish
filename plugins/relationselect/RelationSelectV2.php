<?php
namespace giantbits\crelish\plugins\relationselect;

use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use giantbits\crelish\components\CrelishInputWidget;
use giantbits\crelish\components\CrelishDynamicModel;

/**
 * Class RelationSelectV2
 * 
 * Improved RelationSelect widget using the new architecture
 * 
 * @package giantbits\crelish\plugins\relationselect
 */
class RelationSelectV2 extends CrelishInputWidget
{
    /**
     * @var array Stored items (loaded from database)
     */
    protected $storedItems = [];
    
    /**
     * @var bool Whether to support multiple selections
     */
    public $multiple = false;
    
    /**
     * @var string Content type for relations
     */
    public $ctype;
    
    /**
     * @var array Table columns for display
     */
    public $columns = [];
    
    /**
     * @var bool Whether to show as table view
     */
    public $tableView = false;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        // Get configuration from field
        $this->multiple = $this->getConfig('multiple', false);
        $this->ctype = $this->getConfig('ctype', '');
        $this->columns = $this->getConfig('columns', []);
        $this->tableView = $this->getConfig('tableView', false);
        
        parent::init();
    }

    /**
     * {@inheritdoc}
     */
    protected function registerWidgetAssets()
    {
        // Register Select2 CSS and JS
        $this->registerSelect2Assets();
        
        // Register custom CSS
        $this->registerCustomCSS();
        
        // Register initialization script
        $this->registerTranslations();
    }

    /**
     * {@inheritdoc}
     */
    public function processData($data)
    {
        // Normalize data to array format
        $normalizedData = $this->normalizeToArray($data);
        
        // Load stored items if we have data
        if (!empty($normalizedData)) {
            $this->loadStoredItems($normalizedData);
        }
        
        return $normalizedData;
    }

    /**
     * {@inheritdoc}
     */
    public function renderWidget()
    {
        return $this->renderView('widget', [
            'storedItems' => $this->storedItems,
            'multiple' => $this->multiple,
            'ctype' => $this->ctype,
            'columns' => $this->columns,
            'tableView' => $this->tableView,
            'apiEndpoint' => $this->getApiEndpoint(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getInitializationScript()
    {
        $id = $this->getInputId();
        $config = Json::encode([
            'multiple' => $this->multiple,
            'ctype' => $this->ctype,
            'apiEndpoint' => $this->getApiEndpoint(),
            'tableView' => $this->tableView,
            'columns' => $this->columns,
        ]);
        
        return "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof RelationSelect !== 'undefined') {
                new RelationSelect('#{$id}', {$config});
            } else if (typeof $ !== 'undefined' && $.fn.select2) {
                // Fallback to basic Select2
                $('#{$id}').select2({
                    ajax: {
                        url: '{$this->getApiEndpoint()}',
                        dataType: 'json',
                        delay: 250,
                        processResults: function(data) {
                            return {
                                results: data.items || []
                            };
                        }
                    }
                });
            }
        });
        ";
    }

    /**
     * {@inheritdoc}
     */
    public function loadTranslations()
    {
        $this->translations = [
            'loading' => Yii::t('app', 'Loading...'),
            'noResults' => Yii::t('app', 'No results found'),
            'searching' => Yii::t('app', 'Searching...'),
            'selectItem' => Yii::t('app', 'Select an item'),
            'removeItem' => Yii::t('app', 'Remove'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getClientConfig()
    {
        $config = parent::getClientConfig();
        
        $config['multiple'] = $this->multiple;
        $config['ctype'] = $this->ctype;
        $config['columns'] = $this->columns;
        $config['tableView'] = $this->tableView;
        $config['apiEndpoint'] = $this->getApiEndpoint();
        $config['storedItems'] = $this->storedItems;
        
        return $config;
    }

    /**
     * Normalize data to array format
     * 
     * @param mixed $data
     * @return array
     */
    protected function normalizeToArray($data)
    {
        if (empty($data)) {
            return [];
        }

        // Handle arrays
        if (is_array($data)) {
            return array_values(array_filter($data));
        }

        // Handle objects
        if (is_object($data)) {
            if (isset($data->uuid)) {
                return [$data->uuid];
            }
            return array_values(array_filter((array)$data));
        }

        // Handle JSON strings
        if (is_string($data)) {
            if (substr($data, 0, 1) === '{' || substr($data, 0, 1) === '[') {
                $decoded = Json::decode($data);
                return $this->normalizeToArray($decoded);
            }
            
            // Handle comma-separated values
            if (strpos($data, ',') !== false) {
                return array_map('trim', explode(',', $data));
            }
            
            // Single UUID
            return [$data];
        }

        return [];
    }

    /**
     * Load stored items from database
     * 
     * @param array $uuids
     */
    protected function loadStoredItems($uuids)
    {
        if (empty($uuids) || empty($this->ctype)) {
            return;
        }

        foreach ($uuids as $uuid) {
            if (empty($uuid)) continue;
            
            try {
                $item = new CrelishDynamicModel([
                    'ctype' => $this->ctype,
                    'uuid' => $uuid
                ]);
                
                if (!empty($item->uuid)) {
                    $this->storedItems[] = $item;
                }
            } catch (\Exception $e) {
                Yii::warning("Failed to load related item: $uuid", __METHOD__);
            }
        }
    }

    /**
     * Get API endpoint for AJAX requests
     * 
     * @return string
     */
    protected function getApiEndpoint()
    {
        return "/crelish/content/relation-data?ctype=" . urlencode($this->ctype);
    }

    /**
     * Register Select2 assets
     */
    protected function registerSelect2Assets()
    {
        // Register Select2 CSS
        $this->view->registerCssFile('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
        
        // Register Select2 JS
        $this->view->registerJsFile('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', [
            'depends' => [\yii\web\JqueryAsset::class]
        ]);
    }

    /**
     * Register custom CSS
     */
    protected function registerCustomCSS()
    {
        $css = '
        .relation-select-container {
            margin-bottom: 1rem;
        }
        
        .relation-select-table {
            margin-top: 1rem;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .relation-select-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .relation-select-table th,
        .relation-select-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }
        
        .relation-select-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .relation-select-remove {
            color: #dc3545;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 3px;
        }
        
        .relation-select-remove:hover {
            background-color: #dc3545;
            color: white;
        }
        
        /* Dark mode support */
        [data-theme="dark"] .relation-select-table {
            border-color: #444;
        }
        
        [data-theme="dark"] .relation-select-table th {
            background-color: #2d3748;
            color: #e2e8f0;
        }
        
        [data-theme="dark"] .relation-select-table td {
            border-color: #4a5568;
            color: #e2e8f0;
        }
        ';
        
        $this->view->registerCss($css);
    }

    /**
     * Register translations for JavaScript
     */
    protected function registerTranslations()
    {
        $js = "window.relationSelectTranslations = " . Json::encode($this->translations) . ";";
        $this->view->registerJs($js, \yii\web\View::POS_HEAD);
    }
}