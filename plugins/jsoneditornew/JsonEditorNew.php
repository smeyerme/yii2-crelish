<?php

namespace giantbits\crelish\plugins\jsoneditornew;

use giantbits\crelish\components\CrelishFormWidget;
use Yii;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\View;

/**
 * JSON Editor plugin using @json-editor/json-editor library
 *
 * This library generates actual form inputs from JSON Schema definitions,
 * providing a much cleaner UI for editing complex JSON structures.
 *
 * @see https://github.com/json-editor/json-editor
 */
class JsonEditorNew extends CrelishFormWidget
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
   * @var string Bootstrap version to use (bootstrap3, bootstrap4, bootstrap5)
   */
  public $theme = 'bootstrap5';

  /**
   * @var string|null Icon library to use (fontawesome5, bootstrap-icons, etc.) or null to disable
   */
  public $iconlib = 'fontawesome5';

  /**
   * @var bool Whether to show the editor in a collapsed state
   */
  public $startCollapsed = false;

  /**
   * @var bool Whether to disable the collapse feature
   */
  public $disableCollapse = false;

  /**
   * @var bool Whether to disable editing array items
   */
  public $disableArrayReorder = false;

  /**
   * @var bool Whether to disable the "add row" button
   */
  public $disableArrayAdd = false;

  /**
   * @var bool Whether to disable the "delete row" button
   */
  public $disableArrayDelete = false;

  /**
   * @var bool Whether to show required fields with asterisk
   */
  public $showRequiredByDefault = true;

  /**
   * Initialize the widget
   */
  public function init()
  {
    parent::init();

    // Load @json-editor/json-editor library and capture it to a unique variable
    // This avoids conflict with the old josdejong/jsoneditor which also uses JSONEditor
    // We use an inline script with onload callback to ensure proper capture timing
    $captureJs = <<<JS
(function() {
  // Only load once
  if (window.JSONEditorNewLoaded) return;
  window.JSONEditorNewLoaded = true;

  var script = document.createElement('script');
  script.src = 'https://cdn.jsdelivr.net/npm/@json-editor/json-editor@latest/dist/jsoneditor.min.js';
  script.onload = function() {
    // Immediately capture the library before anything else can overwrite it
    if (typeof JSONEditor !== 'undefined' && typeof JSONEditor.defaults !== 'undefined') {
      window.JSONEditorNew = JSONEditor;
      console.log('JSONEditorNew captured successfully');
    }
  };
  document.head.appendChild(script);
})();
JS;
    $this->view->registerJs($captureJs, View::POS_HEAD);

    // Register custom CSS for additional styling
    $this->registerCustomCss();
  }

  /**
   * Register custom CSS for the editor
   */
  protected function registerCustomCss()
  {
    $css = <<<CSS
/* JSON Editor New - CSS Reset and Custom Styles */
/* Uses CSS variables from crelish-modern.css for theme consistency */

.json-editor-new-container {
  margin-bottom: 1rem;
  line-height: 1.5;
}

/* Reset common elements inside the editor */
.json-editor-new-container *,
.json-editor-new-container *::before,
.json-editor-new-container *::after {
  box-sizing: border-box;
}

.json-editor-new-container label {
  margin-bottom: 0.25rem;
  font-weight: 500;
  display: inline-block;
  color: var(--color-text-dark);
}

.json-editor-new-container .card {
  height: unset;
}

.json-editor-new-container input,
.json-editor-new-container select,
.json-editor-new-container textarea {
  margin: 0;
  font-family: inherit;
  font-size: inherit;
  line-height: inherit;
}

.json-editor-new-container h3,
.json-editor-new-container h4,
.json-editor-new-container h5 {
  margin-top: 0;
  margin-bottom: 0.5rem;
  font-weight: 500;
  line-height: 1.2;
  color: var(--color-text-dark);
}

.json-editor-new-container p {
  margin-top: 0;
  margin-bottom: 0.5rem;
}

.json-editor-new-container .form-group {
  margin-bottom: 1rem;
}

/* Form controls - Light mode */
.json-editor-new-container .form-control {
  display: block;
  width: 100%;
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  line-height: 1.5;
  color: var(--color-text-dark);
  background-color: var(--color-bg-main);
  background-clip: padding-box;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-sm);
  transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.json-editor-new-container .form-control:focus {
  color: var(--color-text-dark);
  background-color: var(--color-bg-main);
  border-color: var(--color-primary-light);
  outline: 0;
  box-shadow: 0 0 0 0.25rem rgba(var(--color-primary-light-rgb), 0.25);
}

/* Buttons - Base styling */
.json-editor-new-container .btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.25rem;
  font-weight: 500;
  line-height: 1.5;
  text-align: center;
  text-decoration: none;
  vertical-align: middle;
  cursor: pointer;
  user-select: none;
  background-color: var(--color-bg-light);
  color: var(--color-text-dark);
  border: 1px solid var(--color-border);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  border-radius: var(--border-radius-md);
  transition: var(--transition-standard);
}

.json-editor-new-container .btn:hover {
  background-color: var(--color-border);
  transform: translateY(-1px);
}

.json-editor-new-container .btn-sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
}

/* Primary/Info buttons */
.json-editor-new-container .btn-primary,
.json-editor-new-container .btn-info {
  background: var(--gradient-primary);
  color: var(--color-text-light) !important;
  border: none;
  box-shadow: var(--shadow-sm);
}

.json-editor-new-container .btn-primary:hover,
.json-editor-new-container .btn-info:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}

/* Secondary/Default buttons */
.json-editor-new-container .btn-secondary,
.json-editor-new-container .btn-default {
  background-color: var(--color-bg-light);
  color: var(--color-text-dark);
  border: 1px solid var(--color-border);
}

.json-editor-new-container .btn-secondary:hover,
.json-editor-new-container .btn-default:hover {
  background-color: var(--color-border);
}

/* Danger buttons */
.json-editor-new-container .btn-danger {
  background-color: rgba(220, 38, 38, 0.1);
  color: #b91c1c;
  border: 1px solid rgba(220, 38, 38, 0.3);
}

.json-editor-new-container .btn-danger:hover {
  background-color: rgba(220, 38, 38, 0.2);
}

/* Success buttons */
.json-editor-new-container .btn-success {
  background-color: rgba(16, 185, 129, 0.1);
  color: #059669;
  border: 1px solid rgba(16, 185, 129, 0.3);
}

.json-editor-new-container .btn-success:hover {
  background-color: rgba(16, 185, 129, 0.2);
}

.json-editor-new-container .row {
  display: flex;
  flex-wrap: wrap;
  margin-right: -0.5rem;
  margin-left: -0.5rem;
  margin-bottom: 0.5rem;
}

.json-editor-new-container .row > * {
  padding-right: 0.5rem;
  padding-left: 0.5rem;
}

/* Cards */
.json-editor-new-container .card {
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-md);
  background-color: var(--color-bg-main);
}

.json-editor-new-container .card-header {
  background-color: var(--color-bg-light);
  border-bottom: 1px solid var(--color-border);
  padding: 0.75rem 1rem;
  color: var(--color-text-dark);
}

.json-editor-new-container .card-body {
  padding: 1rem;
  background-color: var(--color-bg-main);
}

.json-editor-new-container [data-schemapath] > h3 {
  font-size: 1rem;
  margin-bottom: 0.5rem;
  color: var(--color-text-dark);
}

.json-editor-new-container .btn-group {
  margin-bottom: 0.5rem;
}

.json-editor-new-container .je-object__controls,
.json-editor-new-container .je-array__controls {
  margin-bottom: 0.75rem;
}

.json-editor-new-container .je-child-editor-holder {
  margin-left: 0;
  padding-left: 0;
  border-left: none;
}

.json-editor-new-container .je-indented-panel {
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-sm);
  padding: 1rem;
  margin-bottom: 0.75rem;
  background-color: var(--color-bg-light);
}

/* Improve array item styling */
.json-editor-new-container [data-schematype="array"] > .card {
  margin-bottom: 0.5rem;
}

.json-editor-new-container [data-schematype="array"] .je-indented-panel {
  position: relative;
}

/* Better button styling for add/delete */
.json-editor-new-container .json-editor-btn-add,
.json-editor-new-container .json-editor-btn-delete {
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
}

/* Collapsible sections */
.json-editor-new-container .je-header {
  cursor: pointer;
  user-select: none;
  border-radius: var(--border-radius-sm);
  transition: var(--transition-standard);
}

.json-editor-new-container .je-header:hover {
  background-color: var(--color-bg-light);
}

/* Error styling */
.json-editor-new-container .is-invalid {
  border-color: #dc3545 !important;
}

.json-editor-new-container .invalid-feedback {
  display: block;
  color: #dc3545;
  font-size: 0.8rem;
}

/* Description/help text */
.json-editor-new-container .je-desc,
.json-editor-new-container p.help-block {
  font-size: 0.8rem;
  color: var(--color-text-muted);
  margin-top: 0.25rem;
}

/* Required indicator */
.json-editor-new-container .required::after {
  content: " *";
  color: #dc3545;
}

/* Compact mode for simple schemas */
.json-editor-new-container.compact .je-indented-panel {
  padding: 0.5rem;
}

.json-editor-new-container.compact .card-body {
  padding: 0.75rem;
}

/* ===== DARK MODE SUPPORT ===== */

/* Override Bootstrap's bg-* classes in dark mode */
[data-theme="dark"] .json-editor-new-container .bg-light {
  background-color: var(--color-bg-light) !important;
}

[data-theme="dark"] .json-editor-new-container .bg-white {
  background-color: var(--color-bg-main) !important;
}

[data-theme="dark"] .json-editor-new-container .bg-secondary {
  background-color: var(--color-bg-secondary) !important;
}

[data-theme="dark"] .json-editor-new-container .text-dark {
  color: var(--color-text-light) !important;
}

[data-theme="dark"] .json-editor-new-container .text-muted {
  color: var(--color-text-muted) !important;
}

[data-theme="dark"] .json-editor-new-container .border {
  border-color: var(--color-border) !important;
}

[data-theme="dark"] .json-editor-new-container label {
  color: var(--color-text-light);
}

[data-theme="dark"] .json-editor-new-container h3,
[data-theme="dark"] .json-editor-new-container h4,
[data-theme="dark"] .json-editor-new-container h5 {
  color: var(--color-text-light);
}

[data-theme="dark"] .json-editor-new-container [data-schemapath] > h3 {
  color: var(--color-text-light);
}

/* Form controls - Dark mode */
[data-theme="dark"] .json-editor-new-container .form-control {
  background-color: var(--color-bg-light);
  border-color: var(--color-border);
  color: var(--color-text-light);
}

[data-theme="dark"] .json-editor-new-container .form-control:focus {
  background-color: var(--color-bg-secondary);
  border-color: var(--color-primary-light);
  color: var(--color-text-light);
}

[data-theme="dark"] .json-editor-new-container .form-control::placeholder {
  color: var(--color-text-muted);
  opacity: 0.7;
}

/* Select elements - Dark mode */
[data-theme="dark"] .json-editor-new-container select.form-control {
  background-color: var(--color-bg-light);
  color: var(--color-text-light);
}

[data-theme="dark"] .json-editor-new-container select.form-control option {
  background-color: var(--color-bg-main);
  color: var(--color-text-light);
}

/* Buttons - Dark mode */
[data-theme="dark"] .json-editor-new-container .btn {
  background-color: var(--color-bg-secondary);
  color: var(--color-text-light);
  border-color: var(--color-border);
}

[data-theme="dark"] .json-editor-new-container .btn:hover {
  background-color: var(--color-bg-light);
}

[data-theme="dark"] .json-editor-new-container .btn-primary,
[data-theme="dark"] .json-editor-new-container .btn-info {
  background: var(--gradient-primary);
  color: var(--color-text-light) !important;
  border: none;
}

[data-theme="dark"] .json-editor-new-container .btn-secondary,
[data-theme="dark"] .json-editor-new-container .btn-default {
  background-color: var(--color-bg-light);
  color: var(--color-text-light);
  border-color: var(--color-border);
}

[data-theme="dark"] .json-editor-new-container .btn-danger {
  background-color: rgba(220, 38, 38, 0.15);
  color: #f87171;
  border-color: rgba(220, 38, 38, 0.3);
}

[data-theme="dark"] .json-editor-new-container .btn-danger:hover {
  background-color: rgba(220, 38, 38, 0.25);
}

[data-theme="dark"] .json-editor-new-container .btn-success {
  background-color: rgba(16, 185, 129, 0.15);
  color: #34d399;
  border-color: rgba(16, 185, 129, 0.3);
}

[data-theme="dark"] .json-editor-new-container .btn-success:hover {
  background-color: rgba(16, 185, 129, 0.25);
}

/* Cards - Dark mode */
[data-theme="dark"] .json-editor-new-container .card {
  background-color: var(--color-bg-main);
  border-color: var(--color-border);
}

[data-theme="dark"] .json-editor-new-container .card-header {
  background-color: var(--color-bg-secondary);
  border-color: var(--color-border);
  color: var(--color-text-light);
}

[data-theme="dark"] .json-editor-new-container .card-body {
  background-color: var(--color-bg-main);
  color: var(--color-text-light);
}

/* Panels - Dark mode */
[data-theme="dark"] .json-editor-new-container .je-indented-panel {
  background-color: var(--color-bg-secondary);
  border-color: var(--color-border);
}

/* Headers - Dark mode */
[data-theme="dark"] .json-editor-new-container .je-header:hover {
  background-color: var(--color-bg-secondary);
}

/* Help text - Dark mode */
[data-theme="dark"] .json-editor-new-container .je-desc,
[data-theme="dark"] .json-editor-new-container p.help-block {
  color: var(--color-text-muted);
}

/* Error styling - Dark mode */
[data-theme="dark"] .json-editor-new-container .invalid-feedback {
  color: #f77;
}

[data-theme="dark"] .json-editor-new-container .required::after {
  color: #f77;
}

/* Checkboxes - Dark mode */
[data-theme="dark"] .json-editor-new-container .form-check-label {
  color: var(--color-text-light);
}

[data-theme="dark"] .json-editor-new-container .form-check-input:checked {
  background-color: var(--color-primary-light);
  border-color: var(--color-primary-light);
}

/* ===== CATEGORY TABS STYLING ===== */
/* Tab labels - white text on gradient header */
.json-editor-new-container .nav-tabs .nav-link {
  color: rgba(255, 255, 255, 0.8) !important;
  background: transparent !important;
  border: none !important;
  border-bottom: 2px solid transparent !important;
  padding: 0.5rem 1rem;
  transition: all 0.2s ease;
}

.json-editor-new-container .nav-tabs .nav-link:hover {
  color: #fff !important;
  border-bottom-color: rgba(255, 255, 255, 0.5) !important;
}

/* Active tab - white text with bottom border indicator */
.json-editor-new-container .nav-tabs .nav-link.active {
  color: #fff !important;
  background: transparent !important;
  border: none !important;
  border-bottom: 2px solid #fff !important;
}

/* Remove default Bootstrap tab styling */
.json-editor-new-container .card-header-tabs {
  margin-bottom: 0;
  border-bottom: none;
}

.json-editor-new-container .nav-tabs {
  border-bottom: none;
}
CSS;

    $this->view->registerCss($css);
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

    if ($isTranslatable) {
      if (isset($this->model->i18n[$currentLang][$this->field->key])) {
        $jsonData = $this->model->i18n[$currentLang][$this->field->key];
      }
    }

    // If still empty, try to get default value from schema
    if (empty($jsonData)) {
      $jsonData = $this->getDefaultFromSchema();

      if ($isTranslatable) {
        $this->model->i18n[$currentLang][$this->field->key] = $jsonData;
      }
    }

    // Ensure data is in JSON string format for the hidden input
    $jsonString = is_string($jsonData) ? $jsonData : Json::encode($jsonData);

    // Prepare the editor container
    $editorId = 'json-editor-new-' . $this->field->key;
    $inputName = $this->getInputName();

    // Get the schema
    $schema = null;
    if (property_exists($this->field, 'schema')) {
      $schema = $this->field->schema;
    }

    // Build HTML
    $html = Html::beginTag('div', [
      'class' => 'form-group field-crelishdynamicmodel-' . $this->formKey . ($isRequired ? ' required' : '')
    ]);

    // Create the editor label
    $html .= Html::label($this->field->label, $editorId, [
      'class' => 'control-label' . ($isRequired ? ' required' : '')
    ]);

    // Add field description/hint if available
    if (property_exists($this->field, 'description') && !empty($this->field->description)) {
      $html .= Html::tag('p', $this->field->description, ['class' => 'text-muted small mb-2']);
    }

    // Create container for the JSON editor
    $html .= Html::tag('div', '', [
      'id' => $editorId,
      'class' => 'json-editor-new-container',
      'data-language' => $isTranslatable ? $currentLang : null
    ]);

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

    // Register JavaScript to initialize the editor
    $this->registerEditorJs($editorId, $schema, $jsonData);

    return $html;
  }

  /**
   * Register the JavaScript to initialize the JSON editor
   */
  protected function registerEditorJs($editorId, $schema, $initialData)
  {
    // Encode schema and data for JavaScript
    $schemaJson = $schema ? Json::encode($schema) : '{}';
    $dataJson = is_string($initialData) ? $initialData : Json::encode($initialData);

    // Ensure we have valid JSON
    if (empty($dataJson) || $dataJson === 'null') {
      $dataJson = $schema && isset($schema->type) && $schema->type === 'array' ? '[]' : '{}';
    }

    $theme = Json::encode($this->theme);
    $iconlib = Json::encode($this->iconlib);
    $startCollapsed = $this->startCollapsed ? 'true' : 'false';
    $disableCollapse = $this->disableCollapse ? 'true' : 'false';
    $disableArrayReorder = $this->disableArrayReorder ? 'true' : 'false';
    $disableArrayAdd = $this->disableArrayAdd ? 'true' : 'false';
    $disableArrayDelete = $this->disableArrayDelete ? 'true' : 'false';
    $showRequired = $this->showRequiredByDefault ? 'true' : 'false';

    $js = <<<JS
(function() {
  // Wait for JSONEditorNew to be ready (captured from @json-editor/json-editor)
  function initEditor() {
    // Wait specifically for JSONEditorNew - do not fall back to old JSONEditor
    if (typeof window.JSONEditorNew === 'undefined') {
      setTimeout(initEditor, 100);
      return;
    }
    var EditorClass = window.JSONEditorNew;
    
    var container = document.getElementById('{$editorId}');
    var hiddenInput = document.getElementById('hidden-{$editorId}');

    if (!container || !hiddenInput) {
      console.error('JSON Editor New: Container or hidden input not found for {$editorId}');
      return;
    }
    
    JSONEditor.defaults.editors.object.options.collapsed = true;
    JSONEditor.defaults.editors.array.options.collapsed = true;

    // Enable delete confirmation for arrays
    if (!JSONEditor.defaults.callbacks) {
      JSONEditor.defaults.callbacks = {};
    }
    JSONEditor.defaults.callbacks.deleteItem = function(editor, item) {
      return confirm('Diesen Eintrag wirklich löschen?');
    };

    // Parse schema and initial data
    var schema = {$schemaJson};
    var startval = null;

    try {
      startval = JSON.parse('{$dataJson}');
    } catch (e) {
      console.warn('JSON Editor New: Could not parse initial data, using empty value');
      startval = schema.type === 'array' ? [] : {};
    }

    // Editor configuration
    var options = {
      schema: schema,
      startval: startval,
      theme: {$theme},
      compact: true,
      iconlib: {$iconlib},
      collapsed: true,
      disable_properties: true,
      disable_edit_json: true,
      prompt_before_delete: true,
      disable_collapse: {$disableCollapse},
      disable_array_reorder: {$disableArrayReorder},
      disable_array_add: {$disableArrayAdd},
      disable_array_delete: {$disableArrayDelete},
      required_by_default: {$showRequired},
      show_errors: 'change',
      no_additional_properties: true,
      display_required_only: false,
      remove_empty_properties: false,
      use_default_values: true,
      object_layout: 'normal',
      array_controls_top: false
    };

    // Create the editor using the correct library
    var editor = new EditorClass(container, options);

    // Store reference on container
    container._jsonEditor = editor;

    // Update hidden input when editor changes
    editor.on('change', function() {
      var errors = editor.validate();
      var json = editor.getValue();

      try {
        hiddenInput.value = JSON.stringify(json);
      } catch (e) {
        console.error('JSON Editor New: Error stringifying value', e);
      }

      // Show/hide validation errors
      if (errors.length) {
        container.classList.add('has-error');
      } else {
        container.classList.remove('has-error');
      }
    });

    // Handle language visibility for translatable fields
    var containerLang = container.getAttribute('data-language');
    if (containerLang) {
      var langSelect = document.getElementById('language-select');
      if (langSelect && langSelect.value !== containerLang) {
        container.closest('.form-group').style.display = 'none';
      }
    }
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEditor);
  } else {
    initEditor();
  }
})();
JS;

    $this->view->registerJs($js, View::POS_END);
  }

  /**
   * Prepare the i18n structure in the model for translatable fields
   */
  protected function prepareI18nStructure()
  {
    $isTranslatable = property_exists($this->field, 'translatable') && $this->field->translatable === true;

    if ($isTranslatable) {
      if (!property_exists($this->model, 'i18n') || !is_array($this->model->i18n)) {
        $this->model->i18n = [];
      }

      if (isset(Yii::$app->params['crelish']['languages']) && is_array(Yii::$app->params['crelish']['languages'])) {
        foreach (Yii::$app->params['crelish']['languages'] as $lang) {
          if (!isset($this->model->i18n[$lang])) {
            $this->model->i18n[$lang] = [];
          }

          if (!isset($this->model->i18n[$lang][$this->field->key])) {
            $defaultValue = $this->getDefaultFromSchema();
            $this->model->i18n[$lang][$this->field->key] = $defaultValue;
          }
        }
      } else {
        $currentLang = Yii::$app->language;
        if (!isset($this->model->i18n[$currentLang])) {
          $this->model->i18n[$currentLang] = [];
        }

        if (!isset($this->model->i18n[$currentLang][$this->field->key])) {
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

    if ($schema->type === 'array') {
      return [];
    } elseif ($schema->type === 'object') {
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
}