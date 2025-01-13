<?php

namespace giantbits\crelish\plugins\matrixconnector;

use giantbits\crelish\components\CrelishBaseContentProcessor;
use giantbits\crelish\components\CrelishDataProvider;
use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Component;
use yii\helpers\Json;
use yii\helpers\VarDumper;
use yii\web\View;

class MatrixConnectorContentProcessor extends Component
{
  public $data;

  public static function processData($key, $data, &$processedData): void
  {
    $layout = null;

    if (empty($processedData[$key])) {
      $processedData[$key] = [];
    }

    if ($data && $data != '{"main":[]}') {

      if (is_string($data)) {
        $data = Json::decode(stripcslashes(trim($data, '"')));
      }

      // Extract layout
      $layout = json_decode($data['_layout'] ?? '[[]]', true) ?: [[]];
      unset($data['_layout']);

      foreach ($data as $section => $subContent) {

        if ($section === '_layout') {
          continue;
        }

        if (empty($processedData[$key][$section])) {
          $processedData[$key][$section] = '';
        }

        foreach ($subContent as $subContentdata) {
          $processedData[$key][$section] .= (new MatrixConnectorContentProcessor)->renderElement($subContentdata);
        }
      }

      $processedData[$key]['_layout'] = (new MatrixConnectorContentProcessor)->getLayoutedContent($layout, $data);
    }
  }

  private function getLayoutedContent($layout, $data)
  {
    $html = '<div class="matrix-layout">';

    // Render each row
    foreach ($layout as $rowIndex => $row) {
      $html .= $this->renderRow($row, $rowIndex, $data);
    }

    $html .= '</div>';

    return $html;
  }

  private function renderRow($row, $rowIndex, $data)
  {
    $html = '<div class="matrix-row" data-row="' . $rowIndex . '">';

    // Render each column in the row
    foreach ($row as $columnIndex => $areaKey) {
      $html .= $this->renderColumn($areaKey, $data);
    }

    $html .= '</div>';
    return $html;
  }

  private function renderColumn($areaKey, $data)
  {
    $html = '<div class="matrix-column" data-area="' . htmlspecialchars($areaKey) . '">';

    // Render elements in this area
    if (isset($data[$areaKey]) && is_array($data[$areaKey])) {

      foreach ($data[$areaKey] as $element) {
        $html .= $this->renderElement($element);
      }
    }

    $html .= '</div>';
    return $html;
  }

  private function renderElement($element)
  {

    if ($element && !empty($element['ctype']) && !empty($element['uuid'])) {
      $sourceData = new CrelishDynamicModel( ['ctype' => $element['ctype'], 'uuid' => $element['uuid']]);
    }

    $sourceDataOut = CrelishBaseContentProcessor::processContent($element['ctype'], $sourceData);

    if (!empty($processedData['uuid'])) {
      $sourceDataOut['parentUuid'] = $processedData['uuid'];
    }

    $view = file_exists(\Yii::$app->view->theme->basePath . '/frontend/elements/' . $element['ctype'] . '.twig') ? 'elements/' . $element['ctype'] . '.twig' : $element['ctype'] . '.twig';
    return \Yii::$app->controller->renderPartial($view, ['data' => $sourceDataOut]);
  }
}
