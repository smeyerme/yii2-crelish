<?php

namespace giantbits\crelish\plugins\jsonstructureeditor;

use giantbits\crelish\components\CrelishDynamicModel;
use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\helpers\Json;

class JsonEditorController extends Controller
{
  public function actionRenderWidget()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    if (!Yii::$app->request->isAjax) {
      return ['success' => false, 'error' => 'Only AJAX requests allowed'];
    }

    $data = Json::decode(Yii::$app->request->rawBody);

    $fieldDef = $data['fieldDef'] ?? null;
    $fieldKey = $data['fieldKey'] ?? null;
    $path = $data['path'] ?? '';
    $value = $data['value'] ?? '';
    $uniqueId = $data['uniqueId'] ?? '';
    $ctype = $data['ctype'] ?? '';

    if (!$fieldDef || !$fieldKey) {
      return ['success' => false, 'error' => 'Missing required parameters'];
    }

    try {
      // Create a temporary model for widget rendering
      $model = new CrelishDynamicModel(['ctype' => $ctype]);

      // Set the field value
      $model->{$fieldKey} = $value;

      // Convert array to object for consistency with your existing code
      $field = (object) $fieldDef;

      // Generate the form key (similar to your CrelishBaseController logic)
      $formKey = $fieldKey;

      // Use the same widget resolution logic as CrelishBaseController
      $html = $this->buildCustomOrDefaultField($field, $model, $formKey, $value, $uniqueId);

      return [
        'success' => true,
        'html' => $html,
        'js' => '' // You can add any additional JS here if needed
      ];

    } catch (\Exception $e) {
      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }

  private function buildCustomOrDefaultField($field, $model, $fieldKey, $value, $uniqueId)
  {
    $field->type = $field->type ?? "textInput";

    // Use the same class resolution logic as CrelishBaseController
    $class = 'giantbits\crelish\plugins\\' . strtolower($field->type) . '\\' . ucfirst($field->type);

    if (class_exists($class)) {
      return $class::widget([
        'model' => $model,
        'formKey' => $fieldKey,
        'data' => $value,
        'field' => $field,
        'attribute' => $fieldKey // Make sure the widget knows its attribute
      ]);
    } else {
      // Fallback for unknown widget types
      return '<div class="unknown-widget">Unknown widget type: ' . htmlspecialchars($field->type) . '</div>';
    }
  }
}