<?php

namespace giantbits\crelish\plugins\jsonstructureeditor;

use giantbits\crelish\components\CrelishFormWidget;
use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\View;

class JsonStructureEditor extends CrelishFormWidget
{
  public $data;
  public $rawData;
  public $formKey;
  public $field;
  public $value;

  private $structureData = [];
  private $schema = [];

  // Static flag to track if assets have been registered
  private static $assetsRegistered = false;

  public function init()
  {
    parent::init();

    $this->field = $this->model->fieldDefinitions->fields[$this->attribute];
    $this->loadSchema();

    if (!empty($this->model->{$this->attribute})) {
      $this->rawData = $this->model->{$this->attribute};
      $this->processData($this->model->{$this->attribute});
    } else {
      $this->processData(null);
      $this->rawData = [];
    }

    $this->registerAssets();
  }

  private function loadSchema()
  {
    if (!empty($this->field->config->schemaFile)) {
      $schemaPath = Yii::getAlias('@app/workspace/schemas/' . $this->field->config->schemaFile);
      if (file_exists($schemaPath)) {
        $this->schema = Json::decode(file_get_contents($schemaPath));
      } else {
        throw new \Exception("Schema file not found: " . $this->field->config->schemaFile);
      }
    } elseif (!empty($this->field->config->schema)) {
      $this->schema = $this->field->config->schema;
    } else {
      throw new \Exception('No schema defined for JsonStructureEditor. Use "schemaFile" or "schema" in field config.');
    }
  }

  private function processData($data)
  {
    if (empty($data)) {
      $this->structureData = $this->getEmptyStructure();
      return;
    }

    // Handle data that's already been processed by ContentProcessor
    if (is_object($data) || is_array($data)) {
      $this->structureData = (array)$data;
    } elseif (is_string($data)) {
      try {
        $this->structureData = Json::decode($data);
      } catch (\Exception $e) {
        $this->structureData = $this->getEmptyStructure();
      }
    } else {
      $this->structureData = $this->getEmptyStructure();
    }
  }

  private function getEmptyStructure()
  {
    $empty = [];

    // Initialize root fields
    foreach ($this->schema['fields'] ?? [] as $field) {
      $empty[$field['key']] = $field['default'] ?? '';
    }

    // Initialize arrays
    foreach ($this->schema['arrays'] ?? [] as $array) {
      $empty[$array['key']] = [];
    }

    return $empty;
  }

  /**
   * Extract translatable strings from schema and translate them
   */
  private function getTranslations()
  {
    $translations = [
      // Common UI strings
      'add' => Yii::t('app', 'Add'),
      'remove' => Yii::t('app', 'Remove'),
      'moveUp' => Yii::t('app', 'Move Up'),
      'moveDown' => Yii::t('app', 'Move Down'),
      'expand' => Yii::t('app', 'Expand'),
      'collapse' => Yii::t('app', 'Collapse'),
      'item' => Yii::t('app', 'Item'),
      'required' => Yii::t('app', 'Required'),

      // Asset connector translations
      'labelSelectImage' => Yii::t('app', 'Select Media'),
      'labelChangeImage' => Yii::t('app', 'Change Media'),
      'labelClear' => Yii::t('app', 'Entfernen'),
      'labelUploadNewImage' => Yii::t('app', 'Upload New Media'),
      'labelSearchImages' => Yii::t('app', 'Search medias...'),
      'labelAllFileTypes' => Yii::t('app', 'All file types'),
      'labelJpegImages' => Yii::t('app', 'JPEG images'),
      'labelPngImages' => Yii::t('app', 'PNG images'),
      'labelGifImages' => Yii::t('app', 'GIF images'),
      'labelSvgImages' => Yii::t('app', 'SVG images'),
      'labelPdfDocuments' => Yii::t('app', 'PDF documents'),
      'labelLoadingImages' => Yii::t('app', 'Loading medias...'),
      'labelNoImagesFound' => Yii::t('app', 'No images found. Try adjusting your search or upload a new image.'),
      'labelLoadMore' => Yii::t('app', 'Load More'),
      'labelCancel' => Yii::t('app', 'Cancel'),
      'labelSelect' => Yii::t('app', 'Select'),
      'labelUploadingStatus' => Yii::t('app', 'Uploading...'),
      'labelUploadSuccessful' => Yii::t('app', 'Upload successful!'),
      'labelUploadFailed' => Yii::t('app', 'Upload failed. Please try again.'),
      'titleSelectImage' => Yii::t('app', 'Select Media')
    ];

    // Extract and translate schema labels
    $this->extractSchemaTranslations($this->schema, $translations);

    return $translations;
  }

  private function extractSchemaTranslations($schema, &$translations, $prefix = '')
  {
    // Extract field labels
    foreach ($schema['fields'] ?? [] as $field) {
      $key = $prefix . 'field_' . $field['key'] . '_label';
      $translations[$key] = Yii::t('app', $field['label'] ?? $field['key']);

      if (!empty($field['placeholder'])) {
        $placeholderKey = $prefix . 'field_' . $field['key'] . '_placeholder';
        $translations[$placeholderKey] = Yii::t('app', $field['placeholder']);
      }
    }

    // Extract array labels
    foreach ($schema['arrays'] ?? [] as $array) {
      $arrayKey = $prefix . 'array_' . $array['key'] . '_label';
      $translations[$arrayKey] = Yii::t('app', $array['label'] ?? $array['key']);

      $itemKey = $prefix . 'array_' . $array['key'] . '_itemLabel';
      $translations[$itemKey] = Yii::t('app', $array['itemLabel'] ?? 'Item');

      // Recursively extract from nested schemas
      if (!empty($array['itemSchema'])) {
        $nestedPrefix = $prefix . 'array_' . $array['key'] . '_';
        $this->extractSchemaTranslations($array['itemSchema'], $translations, $nestedPrefix);
      }
    }
  }

  public function run()
  {
    $inputName = "CrelishDynamicModel[{$this->field->key}]";
    // Use the attribute name to ensure unique IDs
    $uniqueId = 'json-structure-editor-' . $this->attribute;
    $translations = $this->getTranslations();

    $isRequired = false;
    if (!empty($this->field->rules)) {
      foreach ($this->field->rules as $rule) {
        if (is_array($rule) && in_array('required', $rule)) {
          $isRequired = true;
          break;
        }
      }
    }

    $html = '<div class="form-group field-crelishdynamicmodel-' . $this->attribute . ($isRequired ? ' required' : '') . '">';
    //$html .= '<label class="control-label">' . Html::encode($this->field->label) . '</label>';
    $html .= '<div id="' . $uniqueId . '" class="json-structure-editor" ';
    $html .= 'data-schema="' . htmlspecialchars(Json::encode($this->schema)) . '" ';
    $html .= 'data-field-key="' . htmlspecialchars($this->field->key) . '" ';
    $html .= 'data-unique-id="' . htmlspecialchars($uniqueId) . '" ';
    $html .= 'data-translations="' . htmlspecialchars(Json::encode($translations)) . '" ';
    $html .= 'data-initial-data="' . htmlspecialchars(Json::encode($this->structureData)) . '">';

    // We'll render the initial structure, but JavaScript will take over for dynamic updates
    $html .= '<div class="structure-container"></div>';

    $html .= '</div>';

    // Store as JSON string - gets updated by JavaScript
    $html .= Html::hiddenInput($inputName, Json::encode($this->structureData), [
      'id' => $uniqueId . '-data',
      'class' => 'structure-data-input'
    ]);

    $html .= '<div class="help-block help-block-error"></div>';
    $html .= '</div>';

    return $html;
  }

  private function registerAssets()
  {
    // Only register assets once per page request
    if (self::$assetsRegistered) {
      // Still register the instance-specific initialization
      $this->registerInstanceInitialization();
      return;
    }

    self::$assetsRegistered = true;

    // Register AssetConnector assets first - this is crucial for Vue components
    $assetManager = Yii::$app->assetManager;
    $sourcePath = Yii::getAlias('@vendor/giantbits/yii2-crelish/resources/asset-connector/dist/asset-connector.js');
    $publishedUrl = $assetManager->publish($sourcePath, [
      'forceCopy' => YII_DEBUG,
      'appendTimestamp' => true,
    ])[1];
    $this->view->registerJsFile($publishedUrl);

    // Register AssetConnector translations globally
    $assetConnectorTranslations = [
      'labelSelectImage' => Yii::t('app', 'Select Media'),
      'labelChangeImage' => Yii::t('app', 'Change Media'),
      'labelClear' => Yii::t('app', 'Entfernen'),
      'labelUploadNewImage' => Yii::t('app', 'Upload New Media'),
      'labelSearchImages' => Yii::t('app', 'Search medias...'),
      'labelAllFileTypes' => Yii::t('app', 'All file types'),
      'labelJpegImages' => Yii::t('app', 'JPEG images'),
      'labelPngImages' => Yii::t('app', 'PNG images'),
      'labelGifImages' => Yii::t('app', 'GIF images'),
      'labelSvgImages' => Yii::t('app', 'SVG images'),
      'labelPdfDocuments' => Yii::t('app', 'PDF documents'),
      'labelLoadingImages' => Yii::t('app', 'Loading medias...'),
      'labelNoImagesFound' => Yii::t('app', 'No images found. Try adjusting your search or upload a new image.'),
      'labelLoadMore' => Yii::t('app', 'Load More'),
      'labelCancel' => Yii::t('app', 'Cancel'),
      'labelSelect' => Yii::t('app', 'Select'),
      'labelUploadingStatus' => Yii::t('app', 'Uploading...'),
      'labelUploadSuccessful' => Yii::t('app', 'Upload successful!'),
      'labelUploadFailed' => Yii::t('app', 'Upload failed. Please try again.'),
      'titleSelectImage' => Yii::t('app', 'Select Media')
    ];

    $js = "window.assetConnectorTranslations = " . Json::encode($assetConnectorTranslations) . ";";
    $this->view->registerJs($js, View::POS_HEAD);

    // Register CSS (includes new collapsible styles + asset field styles)
    $css = '
    
    .json-structure-editor {
        border-radius: var(--border-radius-lg);
        background-color: var(--color-bg-light);
        box-shadow: var(--shadow-sm);
        transition: var(--transition-standard);
    }
    
    .structure-container {
        background-color: var(--color-bg-main);
        padding: 1.5rem;
        border-radius: var(--border-radius-md);
        border: 1px solid var(--color-border);
        box-shadow: var(--shadow-sm);
    }
    
    .structure-root-fields {
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--color-border);
    }
    
    .structure-field {
        margin-bottom: 1rem;
    }
    
    .structure-field label {
        display: block;
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: var(--color-text-dark);
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.025em;
    }
    
    /* Widget field specific styling */
    .structure-field[data-field-type="assetConnector"],
    .structure-field[data-field-type="asset"] {
        margin-bottom: 1.5rem;
    }
    
    .structure-field[data-field-type="assetConnector"] .asset-connector-container,
    .structure-field[data-field-type="asset"] .asset-connector-container {
        margin-top: 0.5rem;
    }
    
    .json-widget-container {
        margin-top: 0.5rem;
    }
    
    .widget-loading {
        padding: 20px;
        text-align: center;
        background-color: var(--color-bg-light);
        border: 1px solid var(--color-border);
        border-radius: var(--border-radius-md);
        color: var(--color-text-muted);
    }
    
    .widget-fallback {
        padding: 15px;
        background-color: var(--color-bg-light);
        border: 2px dashed var(--color-border);
        border-radius: var(--border-radius-md);
    }
    
    .widget-fallback p {
        margin: 0 0 10px 0;
        font-weight: 600;
        color: var(--color-text-dark);
    }
    
    .structure-array-field {
        margin: 1.5rem 0;
        border: 1px solid var(--color-border);
        border-radius: var(--border-radius-md);
        padding: 0;
        background-color: var(--color-bg-light);
        transition: var(--transition-standard);
        overflow: hidden;
    }
    
    .array-field-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 1.25rem;
        background: var(--gradient-primary);
        color: var(--color-text-light);
        cursor: pointer;
        user-select: none;
        transition: var(--transition-standard);
    }
    
    .array-field-header:hover {
        background: linear-gradient(135deg, var(--color-primary-dark) 10%, var(--color-primary-light) 90%);
    }
    
    .array-field-header h4 {
        margin: 0;
        color: var(--color-text-light);
        font-weight: 600;
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .array-field-toggle {
        background: none;
        border: none;
        color: var(--color-text-light);
        font-size: 1.2rem;
        cursor: pointer;
        transition: var(--transition-standard);
        padding: 0.25rem;
        border-radius: var(--border-radius-sm);
        display: flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
    }
    
    .array-field-toggle:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }
    
    .array-field-toggle.collapsed {
        transform: rotate(-90deg);
    }
    
    .array-field-content {
        padding: 1.25rem;
        transition: var(--transition-standard);
        overflow: hidden;
    }
    
    .array-field-content.collapsed {
        max-height: 0;
        padding-top: 0;
        padding-bottom: 0;
        opacity: 0;
    }
    
    .array-items-count {
        background-color: rgba(255, 255, 255, 0.2);
        color: var(--color-text-light);
        padding: 0.25rem 0.5rem;
        border-radius: var(--border-radius-sm);
        font-size: 0.75rem;
        font-weight: 500;
    }
    
    .array-item {
        border: 1px solid var(--color-border);
        border-radius: var(--border-radius-md);
        margin-bottom: 1rem;
        padding: 1.25rem;
        background-color: var(--color-bg-main);
        box-shadow: var(--shadow-sm);
        transition: var(--transition-standard);
    }
    
    .array-item:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-1px);
    }
    
    .array-item-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--color-border);
    }
    
    .array-item-header h5 {
        margin: 0;
        color: var(--color-text-dark);
        font-weight: 600;
        font-size: 0.9rem;
    }
    
    .array-item-controls {
        display: flex;
        gap: 0.5rem;
    }
    
    .array-item-controls .btn,
    .array-item-controls .c-button {
        padding: 0.375rem 0.75rem;
        font-size: 0.75rem;
        line-height: 1.2;
        border-radius: var(--border-radius-sm);
        transition: var(--transition-standard);
        font-weight: 500;
        border: 1px solid var(--color-border);
        background-color: var(--color-bg-light);
        color: var(--color-text-dark);
    }
    
    .array-item-controls .btn:hover,
    .array-item-controls .c-button:hover {
        background-color: var(--color-bg-secondary);
        transform: translateY(-1px);
        box-shadow: var(--shadow-sm);
    }
    
    .array-item-controls .btn-danger,
    .array-item-controls .c-button--danger {
        background-color: rgba(220, 38, 38, 0.1);
        color: #b91c1c;
        border-color: rgba(220, 38, 38, 0.3);
    }
    
    .array-item-controls .btn-danger:hover,
    .array-item-controls .c-button--danger:hover {
        background-color: rgba(220, 38, 38, 0.2);
        color: #991b1b;
    }
    
    .array-item-controls .btn-primary,
    .array-item-controls .c-button--primary {
        background-color: rgba(var(--color-primary-light-rgb), 0.1);
        color: var(--color-primary-light);
        border-color: rgba(var(--color-primary-light-rgb), 0.3);
    }
    
    .array-item-controls .btn-primary:hover,
    .array-item-controls .c-button--primary:hover {
        background-color: rgba(var(--color-primary-light-rgb), 0.2);
    }
    
    .array-item-fields {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
    }
    
    /* Special layout for widget fields - take full width */
    .array-item-fields .structure-field[data-field-type="assetConnector"],
    .array-item-fields .structure-field[data-field-type="asset"] {
        grid-column: 1 / -1;
    }
    
    .btn.add-array-item,
    .c-button.add-array-item {
        margin-top: 1rem;
        background: var(--gradient-primary);
        color: var(--color-text-light);
        border: none;
        border-radius: var(--border-radius-md);
        padding: 0.75rem 1.25rem;
        font-weight: 500;
        transition: var(--transition-standard);
        box-shadow: var(--shadow-sm);
    }
    
    .btn.add-array-item:hover,
    .c-button.add-array-item:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }
    
    .structure-input {
        width: 100%;
        background-color: var(--color-bg-main);
        border: 1px solid var(--color-border);
        border-radius: var(--border-radius-md);
        padding: 0.75rem;
        color: var(--color-text-dark);
        transition: var(--transition-standard);
    }
    
    .structure-input:focus {
        outline: none;
        border-color: var(--color-primary-light);
        box-shadow: 0 0 0 0.25rem rgba(var(--color-primary-light-rgb), 0.25);
        background-color: var(--color-bg-main);
    }
    
    /* Nested array styling */
    .array-item .structure-array-field {
        background-color: var(--color-bg-secondary);
        margin-top: 1rem;
        border-color: var(--color-border);
    }
    
    /* Asset connector integration */
    .structure-field .asset-connector-container {
        margin-top: 0.5rem;
    }
    
    /* Dark mode specific adjustments */
    [data-theme="dark"] .json-structure-editor {
        background-color: var(--color-bg-secondary);
    }
    
    [data-theme="dark"] .structure-container {
        background-color: var(--color-bg-main);
    }
    
    [data-theme="dark"] .structure-field label {
        color: var(--color-text-light);
    }
    
    [data-theme="dark"] .array-item-header h5 {
        color: var(--color-text-light);
    }
    
    [data-theme="dark"] .array-item-controls .btn,
    [data-theme="dark"] .array-item-controls .c-button {
        background-color: var(--color-bg-secondary);
        color: var(--color-text-light);
        border-color: var(--color-border);
    }
    
    [data-theme="dark"] .array-item-controls .btn:hover,
    [data-theme="dark"] .array-item-controls .c-button:hover {
        background-color: var(--color-bg-light);
    }
    
    [data-theme="dark"] .array-item-controls .btn-danger,
    [data-theme="dark"] .array-item-controls .c-button--danger {
        background-color: rgba(220, 38, 38, 0.15);
        color: #f87171;
        border-color: rgba(220, 38, 38, 0.3);
    }
    
    [data-theme="dark"] .array-item-controls .btn-danger:hover,
    [data-theme="dark"] .array-item-controls .c-button--danger:hover {
        background-color: rgba(220, 38, 38, 0.25);
    }
    
    [data-theme="dark"] .array-item-controls .btn-primary,
    [data-theme="dark"] .array-item-controls .c-button--primary {
        background-color: rgba(var(--color-primary-light-rgb), 0.15);
        color: var(--color-primary-light);
    }
    
    [data-theme="dark"] .array-item-controls .btn-primary:hover,
    [data-theme="dark"] .array-item-controls .c-button--primary:hover {
        background-color: rgba(var(--color-primary-light-rgb), 0.25);
    }
    
    [data-theme="dark"] .structure-input {
        background-color: var(--color-bg-light);
        color: var(--color-text-light);
    }
    
    [data-theme="dark"] .structure-input:focus {
        background-color: var(--color-bg-secondary);
        color: var(--color-text-light);
    }
    
    [data-theme="dark"] .structure-input::placeholder {
        color: var(--color-text-muted);
        opacity: 0.7;
    }
    
    [data-theme="dark"] .array-item .structure-array-field {
        background-color: var(--color-bg-light);
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        .array-item-fields {
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        
        .array-item-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.75rem;
        }
        
        .array-item-controls {
            align-self: flex-end;
        }
        
        .json-structure-editor {
            padding: 1rem;
        }
        
        .structure-container {
            padding: 1rem;
        }
        
        .array-field-header {
            padding: 0.75rem 1rem;
        }
        
        .array-field-content {
            padding: 1rem;
        }
    }
    
    /* Animation for adding/removing array items */
    @keyframes slideInDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .array-item {
        animation: slideInDown 0.3s ease-out;
    }
    
    /* Enhanced focus states for accessibility */
    .structure-input:focus-visible,
    .btn:focus-visible,
    .c-button:focus-visible {
        outline: 2px solid var(--color-primary-light);
        outline-offset: 2px;
    }
    
    /* Loading state styling */
    .structure-container.loading {
        opacity: 0.7;
        pointer-events: none;
    }
    
    .structure-container.loading::after {
        content: "";
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 20px;
        height: 20px;
        border: 2px solid var(--color-border);
        border-top-color: var(--color-primary-light);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        to {
            transform: translate(-50%, -50%) rotate(360deg);
        }
    }
    ';

    $this->view->registerCss($css);

    // Register enhanced JavaScript with asset connector integration - only once
    $js = "
        // Make AssetConnector translations available globally if not already set
        if (typeof window.assetConnectorTranslations === 'undefined') {
            window.assetConnectorTranslations = " . Json::encode($this->getTranslations()) . ";
        }
        
        // Only define the class if it hasn't been defined yet
        if (typeof window.JsonStructureEditor === 'undefined') {
            window.JsonStructureEditor = class JsonStructureEditor {
                constructor(containerId) {
                    this.container = document.getElementById(containerId);
                    if (!this.container) return;
                    
                    this.schema = JSON.parse(this.container.dataset.schema);
                    this.initialData = JSON.parse(this.container.dataset.initialData);
                    this.translations = JSON.parse(this.container.dataset.translations);
                    this.structureContainer = this.container.querySelector('.structure-container');
                    this.hiddenInput = this.container.parentNode.querySelector('.structure-data-input');
                    
                    // Working data - this is what gets modified
                    this.data = JSON.parse(JSON.stringify(this.initialData));
                    
                    // Track collapsed state of array sections
                    this.collapsedSections = new Set();
                    
                    // Counter for unique asset field IDs
                    this.assetFieldCounter = 0;
                    
                    this.init();
                }
                
                initializeAssetConnector(placeholder, fieldKey, path, value, label, required, inputName) {
                    // Wait for the DOM to be fully updated
                    setTimeout(() => {
                        const container = placeholder.querySelector('.asset-connector-container');
                        if (!container) return;
                        
                        // Try to initialize using the same method as your standalone AssetConnector widget
                        // This attempts to trigger whatever initialization your asset-connector.js does
                        
                        // Method 1: Try to trigger a DOM event that your asset-connector.js might listen for
                        const event = new CustomEvent('assetConnectorInit', {
                            detail: { container: container }
                        });
                        document.dispatchEvent(event);
                        
                        // Method 2: Try calling any global initialization functions
                        if (typeof window.initializeAssetConnector === 'function') {
                            window.initializeAssetConnector(container);
                        } else if (typeof window.AssetConnector === 'function') {
                            new window.AssetConnector(container);
                        }
                        
                        // Method 3: Check if there's a Vue component and try to mount it
                        if (typeof Vue !== 'undefined') {
                            try {
                                new Vue({
                                    el: container,
                                    data: {
                                        fieldKey: fieldKey,
                                        label: label,
                                        inputName: inputName,
                                        value: value,
                                        required: required
                                    },
                                    mounted() {
                                        // Try to initialize the asset connector after Vue mounts
                                        if (typeof window.initializeAssetConnector === 'function') {
                                            window.initializeAssetConnector(this.\$el);
                                        }
                                    }
                                });
                            } catch (e) {
                                console.warn('Could not mount Vue component for AssetConnector:', e);
                                this.createFallbackAssetUI(container, value, inputName);
                            }
                        } else {
                            // If no Vue, create a simple fallback
                            this.createFallbackAssetUI(container, value, inputName);
                        }
                    }, 100);
                }
                
                createFallbackAssetUI(container, value, inputName) {
                    // Create a simple asset selector as fallback
                    container.innerHTML = `
                        <div style=\"border: 2px dashed #ccc; padding: 15px; border-radius: 4px;\">
                            <p><strong>Asset Selector</strong></p>
                            <input type=\"text\" 
                                   placeholder=\"Enter asset UUID or select...\" 
                                   value=\"\${value}\" 
                                   style=\"width: 100%; padding: 8px; margin: 5px 0;\"
                                   onchange=\"
                                       document.getElementById('\${inputName}').value = this.value; 
                                       document.getElementById('\${inputName}').dispatchEvent(new Event('input'));
                                   \">
                            <button type=\"button\" onclick=\"alert('Asset browser would open here')\" 
                                    style=\"padding: 8px 12px; background: #007cba; color: white; border: none; border-radius: 3px;\">
                                Browse Assets
                            </button>
                        </div>
                    `;
                }
                
                init() {
                    this.render();
                    this.bindEvents();
                }
                
                render() {
                    this.structureContainer.innerHTML = this.renderStructure();
                    this.initializeAssetFields();
                    this.updateHiddenInput();
                }
                
                renderStructure() {
                    let html = '<div class=\"structure-editor-wrapper\">';
                    
                    // Render root level fields
                    html += '<div class=\"structure-root-fields\">';
                    for (const field of this.schema.fields || []) {
                        html += this.renderField(field, this.data[field.key] || '', '');
                    }
                    html += '</div>';
                    
                    // Render array fields
                    for (const arrayDef of this.schema.arrays || []) {
                        html += this.renderArrayField(arrayDef, this.data[arrayDef.key] || []);
                    }
                    
                    html += '</div>';
                    return html;
                }
                
                renderField(fieldDef, value, path) {
                    const fieldId = 'field-' + (path ? path + '-' + fieldDef.key : fieldDef.key);
                    const labelKey = this.getTranslationKey('field', fieldDef.key, 'label', path);
                    const placeholderKey = this.getTranslationKey('field', fieldDef.key, 'placeholder', path);
                    const label = this.t(labelKey, fieldDef.label || fieldDef.key);
                    const placeholder = this.t(placeholderKey, fieldDef.placeholder || '');
                    const required = fieldDef.required ? (' ' + this.t('required')) : '';
                    
                    let html = `<div class=\"structure-field\" data-field-type=\"\${fieldDef.type || 'text'}\">`;
                    html += `<label for=\"\${fieldId}\">\${this.escapeHtml(label)}\${required}</label>`;
                    
                    switch (fieldDef.type || 'text') {
                        case 'text':
                            html += `<input type=\"text\" id=\"\${fieldId}\" class=\"form-control structure-input\" `;
                            html += `placeholder=\"\${this.escapeHtml(placeholder)}\" `;
                            html += `data-field=\"\${fieldDef.key}\" data-path=\"\${path}\" `;
                            html += `value=\"\${this.escapeHtml(value)}\">`;
                            break;
                            
                        case 'textarea':
                            html += `<textarea id=\"\${fieldId}\" class=\"form-control structure-input\" `;
                            html += `placeholder=\"\${this.escapeHtml(placeholder)}\" `;
                            html += `data-field=\"\${fieldDef.key}\" data-path=\"\${path}\" `;
                            html += `rows=\"\${fieldDef.rows || 3}\">\${this.escapeHtml(value)}</textarea>`;
                            break;
                            
                        case 'select':
                            html += `<select id=\"\${fieldId}\" class=\"form-control structure-input\" `;
                            html += `data-field=\"\${fieldDef.key}\" data-path=\"\${path}\">`;
                            if (fieldDef.prompt) {
                                html += `<option value=\"\">\${this.escapeHtml(fieldDef.prompt)}</option>`;
                            }
                            for (const [optValue, optLabel] of Object.entries(fieldDef.options || {})) {
                                const selected = value === optValue ? ' selected' : '';
                                html += `<option value=\"\${optValue}\"\${selected}>\${this.escapeHtml(optLabel)}</option>`;
                            }
                            html += '</select>';
                            break;
                            
                        case 'assetConnector':
                        case 'asset':
                            // Generate unique ID for widget field
                            const widgetFieldId = `widget-field-\${++this.assetFieldCounter}`;
                            html += `<div class=\"widget-field-placeholder\" `;
                            html += `id=\"\${widgetFieldId}\" `;
                            html += `data-field=\"\${fieldDef.key}\" `;
                            html += `data-path=\"\${path}\" `;
                            html += `data-value=\"\${this.escapeHtml(value)}\" `;
                            html += `data-label=\"\${this.escapeHtml(label)}\" `;
                            html += `data-widget-type=\"\${fieldDef.type}\" `;
                            html += `data-required=\"\${fieldDef.required ? 'true' : 'false'}\"></div>`;
                            break;
                            
                        default:
                            html += `<input type=\"text\" id=\"\${fieldId}\" class=\"form-control structure-input\" `;
                            html += `placeholder=\"\${this.escapeHtml(placeholder)}\" `;
                            html += `data-field=\"\${fieldDef.key}\" data-path=\"\${path}\" `;
                            html += `value=\"\${this.escapeHtml(value)}\">`;
                    }
                    
                    html += '</div>';
                    return html;
                }
                
                renderArrayField(arrayDef, items, currentPath = '') {
                    const arrayPath = currentPath ? currentPath + '.' + arrayDef.key : arrayDef.key;
                    const sectionId = 'section-' + arrayPath.replace(/\./g, '-');
                    const isCollapsed = this.collapsedSections.has(sectionId);
                    
                    const labelKey = this.getTranslationKey('array', arrayDef.key, 'label', currentPath);
                    const itemLabelKey = this.getTranslationKey('array', arrayDef.key, 'itemLabel', currentPath);
                    const label = this.t(labelKey, arrayDef.label || arrayDef.key);
                    const itemLabel = this.t(itemLabelKey, arrayDef.itemLabel || this.t('item'));
                    
                    let html = `<div class=\"structure-array-field\" data-array-key=\"\${arrayDef.key}\" data-array-path=\"\${currentPath}\" data-section-id=\"\${sectionId}\">`;
                    
                    // Collapsible header
                    html += `<div class=\"array-field-header\" data-toggle-section=\"\${sectionId}\">`;
                    html += '<h4>';
                    html += `<span>\${this.escapeHtml(label)}</span>`;
                    html += `<span class=\"array-items-count\">\${items.length}</span>`;
                    html += '</h4>';
                    html += `<button type=\"button\" class=\"array-field-toggle\${isCollapsed ? ' collapsed' : ''}\" `;
                    html += `title=\"\${isCollapsed ? this.t('expand') : this.t('collapse')}\">`;
                    html += '▼</button>';
                    html += '</div>';
                    
                    // Collapsible content
                    html += `<div class=\"array-field-content\${isCollapsed ? ' collapsed' : ''}\">`;
                    html += '<div class=\"array-items\">';
                    for (let i = 0; i < items.length; i++) {
                        html += this.renderArrayItem(arrayDef, items[i], i, arrayPath, itemLabel);
                    }
                    html += '</div>';
                    
                    html += `<button type=\"button\" class=\"btn btn-primary add-array-item\" data-array=\"\${arrayDef.key}\" data-parent-path=\"\${currentPath}\">`;
                    html += `+ \${this.t('add')} \${this.escapeHtml(itemLabel)}</button>`;
                    html += '</div>';
                    
                    html += '</div>';
                    return html;
                }
                
                renderArrayItem(arrayDef, item, index, currentPath, itemLabel) {
                    const itemPath = currentPath + '.' + index;
                    
                    let html = `<div class=\"array-item\" data-index=\"\${index}\" data-item-path=\"\${itemPath}\">`;
                    html += '<div class=\"array-item-header\">';
                    html += `<h5>\${this.escapeHtml(itemLabel)} \${index + 1}</h5>`;
                    html += '<div class=\"array-item-controls\">';
                    
                    if (index > 0) {
                        html += `<button type=\"button\" class=\"btn btn-sm btn-default move-item-up\" title=\"\${this.t('moveUp')}\">↑</button>`;
                    }
                    html += `<button type=\"button\" class=\"btn btn-sm btn-default move-item-down\" title=\"\${this.t('moveDown')}\">↓</button>`;
                    html += `<button type=\"button\" class=\"btn btn-sm btn-danger remove-array-item\" title=\"\${this.t('remove')}\">×</button>`;
                    
                    html += '</div></div>';
                    
                    html += '<div class=\"array-item-fields\">';
                    for (const field of arrayDef.itemSchema.fields || []) {
                        html += this.renderField(field, item[field.key] || '', itemPath);
                    }
                    html += '</div>';
                    
                    // Handle nested arrays
                    for (const nestedArray of arrayDef.itemSchema.arrays || []) {
                        html += this.renderArrayField(nestedArray, item[nestedArray.key] || [], itemPath);
                    }
                    
                    html += '</div>';
                    return html;
                }
                
                initializeAssetFields() {
                    // Initialize all widget field placeholders (including asset connectors)
                    const widgetPlaceholders = this.structureContainer.querySelectorAll('.widget-field-placeholder');
                    widgetPlaceholders.forEach(placeholder => {
                        this.createWidgetField(placeholder);
                    });
                }
                
                createWidgetField(placeholder) {
                    const fieldKey = placeholder.dataset.field;
                    const path = placeholder.dataset.path;
                    const value = placeholder.dataset.value || '';
                    const label = placeholder.dataset.label || 'Field';
                    const widgetType = placeholder.dataset.widgetType || 'assetConnector';
                    const required = placeholder.dataset.required === 'true';
                    const uniqueId = placeholder.id;
                    
                    // Generate unique input name and field key for the form
                    const inputName = `widget_\${uniqueId}`;
                    const fieldKeyForForm = path ? `\${path}.\${fieldKey}` : fieldKey;
                    
                    // Create a container that will hold the widget
                    let html = `<div class=\"json-widget-container\" `;
                    html += `data-field-key=\"\${fieldKey}\" `;
                    html += `data-path=\"\${path}\" `;
                    html += `data-widget-type=\"\${widgetType}\" `;
                    html += `data-value=\"\${value}\" `;
                    html += `data-required=\"\${required}\">`;
                    
                    // Add the specific widget based on type
                    if (widgetType === 'assetConnector' || widgetType === 'asset') {
                        html += `<div class=\"asset-connector-container\" `;
                        html += `data-field-key=\"\${fieldKey}\" `;
                        html += `data-label=\"\${label}\" `;
                        html += `data-input-name=\"\${inputName}\" `;
                        html += `data-value=\"\${value}\" `;
                        html += `data-required=\"\${required}\">`;
                        html += '</div>';
                    } else {
                        // For other widget types, create a generic container
                        html += `<div class=\"generic-widget-container\" `;
                        html += `data-widget-type=\"\${widgetType}\" `;
                        html += `data-field-key=\"\${fieldKey}\" `;
                        html += `data-value=\"\${value}\">`;
                        html += `<p>Widget type: \${widgetType} (not yet implemented)</p>`;
                        html += '</div>';
                    }
                    
                    // Add hidden input to store the value
                    html += `<input type=\"hidden\" id=\"\${inputName}\" name=\"\${inputName}\" value=\"\${value}\">`;
                    html += '</div>';
                    
                    placeholder.innerHTML = html;
                    
                    // Initialize the specific widget
                    if (widgetType === 'assetConnector' || widgetType === 'asset') {
                        // Initialize asset connector using the same approach as the standalone widget
                        this.initializeAssetConnector(placeholder, fieldKey, path, value, label, required, inputName);
                    }
                    
                    // Listen for value changes on the hidden input
                    const hiddenInput = placeholder.querySelector('input[type=\"hidden\"]');
                    if (hiddenInput) {
                        const observer = new MutationObserver(() => {
                            this.updateDataFromWidgetField(fieldKey, path, hiddenInput.value);
                        });
                        
                        observer.observe(hiddenInput, {
                            attributes: true,
                            attributeFilter: ['value']
                        });
                        
                        // Also listen for input events
                        hiddenInput.addEventListener('input', () => {
                            this.updateDataFromWidgetField(fieldKey, path, hiddenInput.value);
                        });
                    }
                }
                
                updateDataFromWidgetField(field, path, value) {
                    if (!field) return;
                    
                    if (path) {
                        this.setNestedValue(this.data, path + '.' + field, value);
                    } else {
                        this.data[field] = value;
                    }
                    
                    this.updateHiddenInput();
                }
                
                bindEvents() {
                    // Use event delegation for dynamic content
                    this.structureContainer.addEventListener('click', (e) => {
                        if (e.target.closest('[data-toggle-section]')) {
                            e.preventDefault();
                            const header = e.target.closest('[data-toggle-section]');
                            this.toggleSection(header.dataset.toggleSection);
                        } else if (e.target.classList.contains('add-array-item')) {
                            e.preventDefault();
                            this.addArrayItem(e.target);
                        } else if (e.target.classList.contains('remove-array-item')) {
                            e.preventDefault();
                            this.removeArrayItem(e.target);
                        } else if (e.target.classList.contains('move-item-up')) {
                            e.preventDefault();
                            this.moveArrayItem(e.target, -1);
                        } else if (e.target.classList.contains('move-item-down')) {
                            e.preventDefault();
                            this.moveArrayItem(e.target, 1);
                        }
                    });
                    
                    // Handle input changes
                    this.structureContainer.addEventListener('input', (e) => {
                        if (e.target.classList.contains('structure-input')) {
                            this.updateDataFromInput(e.target);
                        }
                    });
                    
                    this.structureContainer.addEventListener('change', (e) => {
                        if (e.target.classList.contains('structure-input')) {
                            this.updateDataFromInput(e.target);
                        }
                    });
                }
                
                toggleSection(sectionId) {
                    const section = this.structureContainer.querySelector(`[data-section-id=\"\${sectionId}\"]`);
                    if (!section) return;
                    
                    const content = section.querySelector('.array-field-content');
                    const toggle = section.querySelector('.array-field-toggle');
                    
                    if (this.collapsedSections.has(sectionId)) {
                        // Expand
                        this.collapsedSections.delete(sectionId);
                        content.classList.remove('collapsed');
                        toggle.classList.remove('collapsed');
                        toggle.title = this.t('collapse');
                    } else {
                        // Collapse
                        this.collapsedSections.add(sectionId);
                        content.classList.add('collapsed');
                        toggle.classList.add('collapsed');
                        toggle.title = this.t('expand');
                    }
                }
                
                updateDataFromInput(input) {
                    const field = input.dataset.field;
                    const path = input.dataset.path;
                    const value = input.value;
                    
                    if (!field) return;
                    
                    if (path) {
                        this.setNestedValue(this.data, path + '.' + field, value);
                    } else {
                        this.data[field] = value;
                    }
                    
                    this.updateHiddenInput();
                }
                
                addArrayItem(button) {
                    const arrayKey = button.dataset.array;
                    const parentPath = button.dataset.parentPath || '';
                    
                    console.log('Adding item to array:', arrayKey, 'with parent path:', parentPath);
                    
                    let targetArray;
                    if (parentPath) {
                        const fullPath = parentPath + '.' + arrayKey;
                        targetArray = this.getNestedValue(this.data, fullPath);
                        if (!targetArray) {
                            // Create the array if it doesn't exist
                            this.setNestedValue(this.data, fullPath, []);
                            targetArray = this.getNestedValue(this.data, fullPath);
                        }
                    } else {
                        if (!this.data[arrayKey]) {
                            this.data[arrayKey] = [];
                        }
                        targetArray = this.data[arrayKey];
                    }
                    
                    // Find array definition in schema
                    const arrayDef = this.findArrayDefinition(arrayKey);
                    if (!arrayDef) {
                        console.error('Array definition not found for:', arrayKey);
                        return;
                    }
                    
                    // Create empty item based on schema
                    const newItem = this.createEmptyItem(arrayDef.itemSchema);
                    targetArray.push(newItem);
                    
                    console.log('New item added:', newItem);
                    console.log('Updated data:', this.data);
                    
                    this.render();
                }
                
                removeArrayItem(button) {
                    const arrayItem = button.closest('.array-item');
                    const index = parseInt(arrayItem.dataset.index);
                    const arrayField = button.closest('.structure-array-field');
                    const arrayKey = arrayField.dataset.arrayKey;
                    const arrayPath = this.getArrayPath(arrayField);
                    
                    let targetArray;
                    if (arrayPath) {
                        targetArray = this.getNestedValue(this.data, arrayPath + '.' + arrayKey);
                    } else {
                        targetArray = this.data[arrayKey];
                    }
                    
                    if (targetArray && index >= 0) {
                        targetArray.splice(index, 1);
                        this.render();
                    }
                }
                
                moveArrayItem(button, direction) {
                    const arrayItem = button.closest('.array-item');
                    const index = parseInt(arrayItem.dataset.index);
                    const arrayField = button.closest('.structure-array-field');
                    const arrayKey = arrayField.dataset.arrayKey;
                    const arrayPath = this.getArrayPath(arrayField);
                    
                    let targetArray;
                    if (arrayPath) {
                        targetArray = this.getNestedValue(this.data, arrayPath + '.' + arrayKey);
                    } else {
                        targetArray = this.data[arrayKey];
                    }
                    
                    if (!targetArray) return;
                    
                    const newIndex = index + direction;
                    if (newIndex >= 0 && newIndex < targetArray.length) {
                        // Swap items
                        [targetArray[index], targetArray[newIndex]] = [targetArray[newIndex], targetArray[index]];
                        this.render();
                    }
                }
                
                createEmptyItem(itemSchema) {
                    const item = {};
                    
                    // Initialize fields
                    for (const field of itemSchema.fields || []) {
                        item[field.key] = field.default || '';
                    }
                    
                    // Initialize nested arrays
                    for (const nestedArray of itemSchema.arrays || []) {
                        item[nestedArray.key] = [];
                    }
                    
                    return item;
                }
                
                findArrayDefinition(arrayKey, schema = this.schema) {
                    for (const arrayDef of schema.arrays || []) {
                        if (arrayDef.key === arrayKey) {
                            return arrayDef;
                        }
                        
                        // Search in nested schemas
                        const nested = this.findArrayDefinition(arrayKey, arrayDef.itemSchema);
                        if (nested) return nested;
                    }
                    return null;
                }
                
                getArrayPath(element) {
                    // Check if the element has a data-parent-path attribute (for add buttons)
                    if (element.dataset && element.dataset.parentPath) {
                        return element.dataset.parentPath;
                    }
                    
                    // For other elements, traverse up to find the path
                    const paths = [];
                    let current = element;
                    
                    while (current && !current.classList.contains('structure-container')) {
                        if (current.classList.contains('array-item') && current.dataset.itemPath) {
                            return current.dataset.itemPath.split('.').slice(0, -1).join('.');
                        }
                        
                        if (current.classList.contains('structure-array-field') && current.dataset.arrayPath) {
                            return current.dataset.arrayPath;
                        }
                        
                        current = current.parentElement;
                    }
                    
                    return '';
                }
                
                setNestedValue(obj, path, value) {
                    const keys = path.split('.');
                    let current = obj;
                    
                    for (let i = 0; i < keys.length - 1; i++) {
                        const key = keys[i];
                        if (!(key in current)) {
                            current[key] = isNaN(keys[i + 1]) ? {} : [];
                        }
                        current = current[key];
                    }
                    
                    current[keys[keys.length - 1]] = value;
                }
                
                getNestedValue(obj, path) {
                    const keys = path.split('.');
                    let current = obj;
                    
                    for (const key of keys) {
                        if (current && key in current) {
                            current = current[key];
                        } else {
                            return undefined;
                        }
                    }
                    
                    return current;
                }
                
                updateHiddenInput() {
                    if (this.hiddenInput) {
                        this.hiddenInput.value = JSON.stringify(this.data);
                    }
                }
                
                // Translation helper methods
                getTranslationKey(type, key, suffix, path = '') {
                    const prefix = path ? path.replace(/\./g, '_') + '_' : '';
                    return prefix + type + '_' + key + '_' + suffix;
                }
                
                t(key, fallback = '') {
                    return this.translations[key] || fallback || key;
                }
                
                escapeHtml(text) {
                    if (typeof text !== 'string') return '';
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }
            };
        }
        ";

    $this->view->registerJs($js, View::POS_END);

    // Register the instance-specific initialization
    $this->registerInstanceInitialization();
  }

  private function registerInstanceInitialization()
  {
    // Register initialization script for this specific instance
    $uniqueId = 'json-structure-editor-' . $this->attribute;
    $initJs = "
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('{$uniqueId}')) {
                new window.JsonStructureEditor('{$uniqueId}');
            }
        });
    ";

    $this->view->registerJs($initJs, View::POS_END);
  }
}