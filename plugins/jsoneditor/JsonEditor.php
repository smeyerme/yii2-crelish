<?php

namespace giantbits\crelish\plugins\jsoneditor;

use giantbits\crelish\components\CrelishFormWidget;
use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\View;

/**
 * JSON Editor plugin for Crelish CMS
 * Renders a UI for editing JSON data based on a provided schema
 */
class JsonEditor extends CrelishFormWidget
{
  /**
   * @var mixed The data to edit
   */
  public $data;
  
  /**
   * @var string The form key
   */
  public $formKey;
  
  /**
   * @var object The field definition
   */
  public $field;
  public $attribute;

  /**
   * Initialize the widget
   */
  public function init()
  {
    parent::init();
    
    // Get the AssetManager instance
    $assetManager = Yii::$app->assetManager;
    
    // Register jQuery first to ensure it's available
    $this->view->registerJsFile(
      'https://code.jquery.com/jquery-3.6.0.min.js',
      ['position' => View::POS_HEAD]
    );
    
    // Register JSON Schema library for validation first
    $this->view->registerJsFile(
      'https://cdnjs.cloudflare.com/ajax/libs/ajv/6.12.6/ajv.min.js',
      ['position' => View::POS_HEAD]
    );
    
    // Register JSON Editor library (JsonEditor.js)
    $jsonEditorJs = Yii::getAlias('@vendor/npm-asset/jsoneditor/dist/jsoneditor.min.js');
    $jsonEditorCss = Yii::getAlias('@vendor/npm-asset/jsoneditor/dist/jsoneditor.min.css');
    
    if (file_exists($jsonEditorJs) && file_exists($jsonEditorCss)) {
      $this->view->registerCssFile($assetManager->publish($jsonEditorCss)[1]);
      $this->view->registerJsFile(
        $assetManager->publish($jsonEditorJs)[1],
        ['position' => View::POS_HEAD, 'depends' => 'yii\web\JqueryAsset']
      );
    } else {
      // Fallback to CDN if local files not found
      $this->view->registerCssFile('https://cdnjs.cloudflare.com/ajax/libs/jsoneditor/9.10.2/jsoneditor.min.css');
      $this->view->registerJsFile(
        'https://cdnjs.cloudflare.com/ajax/libs/jsoneditor/9.10.2/jsoneditor.min.js',
        ['position' => View::POS_HEAD, 'depends' => 'yii\web\JqueryAsset']
      );
    }
    
    // Register our custom CSS
    $customCss = Yii::getAlias('@vendor/giantbits/yii2-crelish/resources/jsoneditor/dist/jsoneditor-custom.css');
    if (file_exists($customCss)) {
      $this->view->registerCssFile($assetManager->publish($customCss)[1]);
    }
    
    // Register our custom script last, after all dependencies
    $customJs = Yii::getAlias('@vendor/giantbits/yii2-crelish/resources/jsoneditor/dist/jsoneditor-custom.js');
    if (file_exists($customJs)) {
      $this->view->registerJsFile(
        $assetManager->publish($customJs)[1],
        ['position' => View::POS_END, 'depends' => 'yii\web\JqueryAsset']
      );
    }
  }
  
  /**
   * Run the widget
   * @return string the HTML markup
   */
  public function run()
  {
    // Ensure i18n structure is prepared for translatable fields
    $this->prepareI18nStructure();
    
    // Check if field is required
    $isRequired = false;
    if (!empty($this->field->rules)) {
      foreach ($this->field->rules as $rule) {
        if (is_array($rule) && in_array('required', $rule)) {
          $isRequired = true;
          break;
        }
      }
    }
    
    // Get current data or default value
    $jsonData = $this->data;
    
    // Handle translatable fields
    $currentLang = Yii::$app->language;
    $isTranslatable = property_exists($this->field, 'translatable') && $this->field->translatable === true;
    
    // Special handling for translated fields that come from i18n data
    if ($isTranslatable) {
      // Now check if we have a value for the current field
      if (isset($this->model->i18n[$currentLang][$this->field->key])) {
        $jsonData = $this->model->i18n[$currentLang][$this->field->key];
      }
    }
    
    // If still empty, try to get default value from schema
    if (empty($jsonData)) {
      $jsonData = $this->getDefaultFromSchema();
      
      // Store the default value in the model
      if ($isTranslatable) {
        $this->model->i18n[$currentLang][$this->field->key] = $jsonData;
      }
    }
    
    // Ensure data is in JSON string format for the hidden input
    $jsonString = is_string($jsonData) ? $jsonData : Json::encode($jsonData);
    
    // Prepare the editor container
    $editorId = 'json-editor-' . $this->field->key;
    $inputName = $this->getInputName();
    
    // Get the schema as JSON string if available
    $schemaAttr = '';
    if (property_exists($this->field, 'schema')) {
      $schemaAttr = ' data-schema="' . htmlspecialchars(Json::encode($this->field->schema)) . '"';
    }
    
    // Determine preferred editor mode
    $preferredMode = $this->getPreferredMode();
    $modeAttr = ' data-mode="' . $preferredMode . '"';
    
    // Create the form group container
    $html = Html::beginTag('div', [
      'class' => 'form-group field-crelishdynamicmodel-' . $this->formKey . ($isRequired ? ' required' : '')
    ]);
    
    // Create the editor label
    $html .= Html::label($this->field->label, $editorId, [
      'class' => 'control-label' . ($isRequired ? ' required' : '')
    ]);
    
    // Add mode selector
    $html .= $this->renderModeSelector($editorId, $preferredMode);
    
    // Add language attribute for translatable fields
    $langAttr = '';
    if ($isTranslatable) {
      $langAttr = ' data-language="' . $currentLang . '"';
    }
    
    // Create container for the JSON editor
    $html .= '<div id="' . $editorId . '" class="json-editor-container"' . $schemaAttr . $langAttr . $modeAttr . ' style="height: 400px; margin-bottom: 10px;"></div>';
    
    // Create hidden input to store the JSON data
    $html .= Html::hiddenInput($inputName, $jsonString, [
      'id' => 'hidden-' . $editorId,
      'data-language' => $isTranslatable ? $currentLang : null
    ]);
    
    // Add error container
    $html .= Html::tag('div', '', [
      'class' => 'help-block help-block-error'
    ]);
    
    $html .= Html::endTag('div');
    
    // Register JavaScript to initialize mode selector
    $this->registerModeSelectorJs();
    
    return $html;
  }
  
  /**
   * Prepare the i18n structure in the model for translatable fields
   */
  protected function prepareI18nStructure()
  {
    $isTranslatable = property_exists($this->field, 'translatable') && $this->field->translatable === true;
    
    if ($isTranslatable) {
      // Ensure the i18n property exists and is an array
      if (!property_exists($this->model, 'i18n') || !is_array($this->model->i18n)) {
        $this->model->i18n = [];
      }
      
      // Ensure language arrays exist for all supported languages
      if (isset(Yii::$app->params['crelish']['languages']) && is_array(Yii::$app->params['crelish']['languages'])) {
        foreach (Yii::$app->params['crelish']['languages'] as $lang) {
          if (!isset($this->model->i18n[$lang])) {
            $this->model->i18n[$lang] = [];
          }
          
          // Initialize the field for each language if it doesn't exist
          if (!isset($this->model->i18n[$lang][$this->field->key])) {
            // Use the default from schema if available
            $defaultValue = $this->getDefaultFromSchema();
            $this->model->i18n[$lang][$this->field->key] = $defaultValue;
          }
        }
      } else {
        // Fallback to current language
        $currentLang = Yii::$app->language;
        if (!isset($this->model->i18n[$currentLang])) {
          $this->model->i18n[$currentLang] = [];
        }
        
        // Initialize the field for current language if it doesn't exist
        if (!isset($this->model->i18n[$currentLang][$this->field->key])) {
          // Use the default from schema if available
          $defaultValue = $this->getDefaultFromSchema();
          $this->model->i18n[$currentLang][$this->field->key] = $defaultValue;
        }
      }
    }
  }
  
  /**
   * Get the appropriate input name based on translatable status
   * @return string The input name
   */
  protected function getInputName()
  {
    $isTranslatable = property_exists($this->field, 'translatable') && $this->field->translatable === true;
    $currentLang = Yii::$app->language;
    
    if ($isTranslatable) {
      return "CrelishDynamicModel[i18n][{$currentLang}][{$this->field->key}]";
    } else {
      return "CrelishDynamicModel[{$this->formKey}]";
    }
  }
  
  /**
   * Get default values from the schema
   * @return array|null
   */
  protected function getDefaultFromSchema()
  {
    if (!property_exists($this->field, 'schema')) {
      return null;
    }
    
    $schema = $this->field->schema;
    
    // Check schema type and create appropriate default structure
    if ($schema->type === 'array') {
      return [];
    } elseif ($schema->type === 'object') {
      // Create object with default values from properties
      $defaults = new \stdClass();
      if (property_exists($schema, 'properties')) {
        foreach ($schema->properties as $propName => $propSchema) {
          if (property_exists($propSchema, 'default')) {
            $defaults->{$propName} = $propSchema->default;
          }
        }
      }
      return $defaults;
    }
    
    return null;
  }
  
  /**
   * Get the preferred editor mode based on schema and field type
   * @return string The preferred editor mode
   */
  protected function getPreferredMode()
  {
    // Default mode
    $mode = 'tree';
    
    // Check for schema type
    if (property_exists($this->field, 'schema')) {
      $schema = $this->field->schema;
      
      // For array of simple objects, form mode is often better
      if ($schema->type === 'array' && 
          property_exists($schema, 'items') && 
          $schema->items->type === 'object') {
        
        // Check if it has simple properties (not nested objects)
        $hasNestedObjects = false;
        if (property_exists($schema->items, 'properties')) {
          foreach ($schema->items->properties as $prop) {
            if ($prop->type === 'object' || $prop->type === 'array') {
              $hasNestedObjects = true;
              break;
            }
          }
        }
        
        if (!$hasNestedObjects) {
          $mode = 'form';
        }
      }
      
      // For simple objects with few properties, form mode is good
      elseif ($schema->type === 'object' && 
              property_exists($schema, 'properties') &&
              count(get_object_vars($schema->properties)) <= 5) {
        $mode = 'form';
      }
    }
    
    return $mode;
  }
  
  /**
   * Render mode selector for the JSON editor
   * @param string $editorId The editor ID
   * @param string $currentMode The current editor mode
   * @return string HTML for mode selector
   */
  protected function renderModeSelector($editorId, $currentMode)
  {
    $modes = [
      'tree' => Yii::t('app', 'Tree View'),
      'form' => Yii::t('app', 'Form View'),
      'code' => Yii::t('app', 'Code View'),
      'text' => Yii::t('app', 'Text View'),
      'view' => Yii::t('app', 'Preview')
    ];
    
    $html = '<div class="json-editor-mode-selector" style="margin-bottom: 10px;">';
    $html .= '<label style="margin-right: 5px;">' . Yii::t('app', 'Editor Mode:') . '</label>';
    $html .= '<select id="mode-selector-' . $editorId . '" class="form-control" style="display: inline-block; width: auto;">';
    
    foreach ($modes as $mode => $label) {
      $selected = $mode === $currentMode ? ' selected' : '';
      $html .= '<option value="' . $mode . '"' . $selected . '>' . $label . '</option>';
    }
    
    $html .= '</select>';
    $html .= '</div>';
    
    return $html;
  }
  
  /**
   * Register JavaScript for the mode selector
   */
  protected function registerModeSelectorJs()
  {
    $js = <<<JS
document.addEventListener('DOMContentLoaded', function() {
  // Find all mode selectors
  var selectors = document.querySelectorAll('.json-editor-mode-selector select');
  
  selectors.forEach(function(selector) {
    var editorId = selector.id.replace('mode-selector-', '');
    var editorContainer = document.getElementById(editorId);
    
    if (editorContainer && editorContainer._jsonEditor) {
      // Set initial mode from data attribute
      var initialMode = editorContainer.getAttribute('data-mode');
      if (initialMode && editorContainer._jsonEditor.setMode) {
        editorContainer._jsonEditor.setMode(initialMode);
        selector.value = initialMode;
      }
      
      // Add change event listener
      selector.addEventListener('change', function() {
        var mode = this.value;
        if (editorContainer._jsonEditor && editorContainer._jsonEditor.setMode) {
          editorContainer._jsonEditor.setMode(mode);
        }
      });
    } else {
      // If editor not initialized yet, wait for it
      var checkInterval = setInterval(function() {
        if (editorContainer && editorContainer._jsonEditor) {
          var initialMode = editorContainer.getAttribute('data-mode');
          if (initialMode && editorContainer._jsonEditor.setMode) {
            editorContainer._jsonEditor.setMode(initialMode);
            selector.value = initialMode;
          }
          
          selector.addEventListener('change', function() {
            var mode = this.value;
            if (editorContainer._jsonEditor && editorContainer._jsonEditor.setMode) {
              editorContainer._jsonEditor.setMode(mode);
            }
          });
          
          clearInterval(checkInterval);
        }
      }, 100);
    }
  });
});
JS;
    
    $this->view->registerJs($js, View::POS_END);
  }
} 