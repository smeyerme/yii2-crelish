<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishWidgetFactory;
use Yii;
use yii\web\Response;
use yii\helpers\Json;

class JsonEditorController extends CrelishBaseController
{
  /**
   * Disable CSRF validation for render-widget action
   */
  public function beforeAction($action)
  {
    if ($action->id === 'render-widget') {
      $this->enableCsrfValidation = false;
    }
    return parent::beforeAction($action);
  }

  public function actionRenderWidget()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    // More flexible AJAX detection
    $isAjax = Yii::$app->request->isAjax || 
              Yii::$app->request->headers->get('X-Requested-With') === 'XMLHttpRequest' ||
              Yii::$app->request->headers->get('Content-Type') === 'application/json';

    if (!$isAjax) {
      return ['success' => false, 'error' => 'Only AJAX requests allowed'];
    }

    // Handle both JSON and form data
    $rawBody = Yii::$app->request->rawBody;
    if (!empty($rawBody) && (strpos($rawBody, '{') === 0)) {
      $data = Json::decode($rawBody);
    } else {
      $data = Yii::$app->request->post();
    }

    $fieldDef = $data['fieldDef'] ?? null;
    $path = $data['path'] ?? '';
    $value = $data['value'] ?? '';
    $editorId = $data['editorId'] ?? '';

    if (!$fieldDef) {
      return ['success' => false, 'error' => 'Field definition is required'];
    }

    try {
      // Create a temporary model for widget rendering
      $model = new CrelishDynamicModel();
      $model->defineAttribute($fieldDef['key'], $value);
      
      // Add field definitions to model (required by some widgets)
      $model->fieldDefinitions = new \stdClass();
      $model->fieldDefinitions->fields = [
        $fieldDef['key'] => $this->createFieldObject($fieldDef)
      ];
      
      // Set the attribute value
      $model->{$fieldDef['key']} = $value;
      
      // Render the widget
      $result = $this->renderWidget($fieldDef, $model, $value);
      
      // Handle both old and new return formats
      if (is_array($result) && isset($result['html'])) {
        return [
          'success' => true,
          'html' => $result['html'],
          'scripts' => $result['script'] ?? '',
          'assets' => $result['assets'] ?? [],
          'translations' => $result['translations'] ?? [],
          'widgetInfo' => $result['widgetInfo'] ?? null
        ];
      } else {
        // Fallback for old format
        return [
          'success' => true,
          'html' => $result,
          'scripts' => ''
        ];
      }

    } catch (\Exception $e) {
      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  /**
   * Creates a field object from field definition array
   */
  private function createFieldObject($fieldDef)
  {
    $field = new \stdClass();
    $field->key = $fieldDef['key'];
    $field->label = $fieldDef['label'] ?? '';
    $field->rules = $fieldDef['rules'] ?? [];
    $field->config = isset($fieldDef['config']) ? (object)$fieldDef['config'] : new \stdClass();
    $field->type = $fieldDef['type'] ?? 'text';
    
    // Copy any other field properties
    foreach ($fieldDef as $key => $val) {
      if (!property_exists($field, $key)) {
        $field->$key = $val;
      }
    }
    
    return $field;
  }

  /**
   * Renders a widget based on field definition
   */
  private function renderWidget($fieldDef, $model, $value)
  {
    try {
      // Use the CrelishWidgetFactory for widget creation and rendering
      $factory = new CrelishWidgetFactory();
      
      // Convert field definition to factory format
      $factoryFieldDef = [
        'key' => $fieldDef['key'],
        'type' => $fieldDef['type'],
        'label' => $fieldDef['label'] ?? '',
        'config' => $fieldDef['config'] ?? [],
        'widgetOptions' => $fieldDef['widgetOptions'] ?? []
      ];
      
      // Add explicit widget class if specified
      if (!empty($fieldDef['widgetClass'])) {
        $factoryFieldDef['widgetClass'] = $fieldDef['widgetClass'];
      }
      
      // Create and render the widget using the factory
      $widget = $factory->createWidget($factoryFieldDef, $model, $value);
      $html = $factory->renderWidget($widget, ['context' => 'jsonStructureEditor']);
      
      // Collect asset information for main page registration
      $assets = [];
      if (method_exists($widget, 'getAssetUrls')) {
        $assets = $widget->getAssetUrls();
      } elseif (property_exists($widget, 'assetPath') && !empty($widget->assetPath)) {
        // For V2 widgets, get the asset path
        $assetManager = Yii::$app->assetManager;
        $publishedUrl = $assetManager->publish($widget->assetPath, [
          'forceCopy' => YII_DEBUG,
          'appendTimestamp' => true,
        ])[1];
        $assets = [$publishedUrl];
      }
      
      // Get initialization script from widget
      $script = '';
      if (method_exists($widget, 'getInitializationScript')) {
        $script = $widget->getInitializationScript();
      }
      
      // Get translations if available
      $translations = [];
      if (method_exists($widget, 'getTranslations') && method_exists($widget, 'loadTranslations')) {
        $widget->loadTranslations();
        $translations = $widget->getTranslations();
      }
      
      return [
        'html' => $html,
        'script' => $script,
        'assets' => $assets,
        'translations' => $translations,
        'widgetInfo' => [
          'type' => $fieldDef['type'],
          'version' => CrelishWidgetFactory::getWidgetInfo($fieldDef['type'])['version'] ?? 'unknown'
        ]
      ];
      
    } catch (\Exception $e) {
      return [
        'html' => '<div class="alert alert-danger">Error rendering widget: ' . htmlspecialchars($e->getMessage()) . '</div>',
        'script' => '',
        'widgetInfo' => null
      ];
    }
  }
}