<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use Yii;
use yii\web\Response;
use yii\helpers\Json;

/**
 * Analytics controller for the Crelish module
 */
class AnalyticsController extends CrelishBaseController
{
  /**
   * @inheritdoc
   */
  public function init()
  {
    parent::init();
    Yii::$app->view->title = 'Website Analytics';
  }

  /**
   * Dashboard index
   */
  public function actionIndex()
  {
    $period = Yii::$app->request->get('period', 'month');
    $excludeBots = Yii::$app->request->get('exclude_bots', 1);

    return $this->render('index', [
      'period' => $period,
      'excludeBots' => $excludeBots
    ]);
  }

  /**
   * Get page view stats as JSON
   */
  public function actionPageViewStats()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $period = Yii::$app->request->get('period', 'month');
    $excludeBots = Yii::$app->request->get('exclude_bots', 1);

    return Yii::$app->crelishAnalytics->getPageViewStats($period, (bool)$excludeBots);
  }

  /**
   * Get top pages as JSON
   */
  public function actionTopPages()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $period = Yii::$app->request->get('period', 'month');
    $limit = Yii::$app->request->get('limit', 10);
    $excludeBots = Yii::$app->request->get('exclude_bots', 1);

    $pages = Yii::$app->crelishAnalytics->getTopPages($period, $limit, (bool)$excludeBots);

    // Enrich with page titles
    foreach ($pages as &$page) {

      // Try to get page title from database
      $pageModel = call_user_func('app\workspace\models\\' . ucfirst($page['page_type']) . '::find')
        ->where(['uuid' => $page['page_uuid']])
        ->one();

      if ($pageModel && isset($pageModel['systitle'])) {
        $page['title'] = $pageModel['systitle'];
      } else {
        $page['title'] = 'Unknown Page';
      }
    }

    return $pages;
  }

  /**
   * Get top elements as JSON
   */
  public function actionTopElements()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $period = Yii::$app->request->get('period', 'month');
    $limit = Yii::$app->request->get('limit', 10);
    
    // Only pass the type parameter if it's explicitly provided in the request
    $type = Yii::$app->request->get('type');
    $elements = $type !== null 
        ? Yii::$app->crelishAnalytics->getTopElements($period, $limit, $type)
        : Yii::$app->crelishAnalytics->getTopElements($period, $limit);

    // Enrich with element titles if possible
    foreach ($elements as &$element) {
      // For assets (especially downloads), try to get more info
      if ($element['element_type'] === 'asset') {
        $assetModel = \app\workspace\models\Asset::findOne($element['element_uuid']);
        if ($assetModel) {
          $element['title'] = $assetModel->title ?? $assetModel->fileName ?? ('Asset: ' . $element['element_uuid']);
          $element['file_type'] = $assetModel->mime ?? 'Unknown';
          $element['file_size'] = $assetModel->size ?? 0;
        } else {
          $element['title'] = 'Asset: ' . $element['element_uuid'];
        }
      } else {
        // Try to get element title from database based on type
        try {
          $modelClass = 'app\workspace\models\\' . ucfirst($element['element_type']);
          if (class_exists($modelClass)) {
            $elementModel = call_user_func($modelClass . '::find')
              ->where(['uuid' => $element['element_uuid']])
              ->one();

            if ($elementModel && isset($elementModel['systitle'])) {
              $element['title'] = $elementModel['systitle'];
            } else {
              $element['title'] = 'Element: ' . $element['element_uuid'];
            }
          } else {
            $element['title'] = ucfirst($element['element_type']) . ': ' . $element['element_uuid'];
          }
        } catch (\Exception $e) {
          $element['title'] = ucfirst($element['element_type']) . ': ' . $element['element_uuid'];
          Yii::warning('Failed to load element model: ' . $e->getMessage());
        }
      }
      
      // Add type info for display in the UI
      $element['view_type'] = $element['type'] ?? 'view';
    }

    return $elements;
  }

  /**
   * Get bot statistics
   */
  public function actionBotStats()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $period = Yii::$app->request->get('period', 'month');

    $botStats = (new \yii\db\Query())
      ->select([
        'user_agent',
        'count' => 'COUNT(*)'
      ])
      ->from('analytics_page_views')
      ->where(['is_bot' => 1])
      ->andWhere(['>=', 'created_at', $this->getPeriodStartDate($period)])
      ->groupBy(['user_agent'])
      ->orderBy(['count' => SORT_DESC])
      ->limit(20)
      ->all();

    return $botStats;
  }

  /**
   * Export analytics data to CSV
   */
  public function actionExport()
  {
    $period = Yii::$app->request->get('period', 'month');
    $excludeBots = Yii::$app->request->get('exclude_bots', 1);
    $type = Yii::$app->request->get('type', 'page_views');

    $filename = 'analytics_' . $type . '_' . date('Y-m-d') . '.csv';

    $query = (new \yii\db\Query());

    if ($type === 'page_views') {
      $query->select(['page_uuid', 'page_type', 'url', 'session_id', 'user_id', 'created_at'])
        ->from('analytics_page_views');

      if ($excludeBots) {
        $query->where(['is_bot' => 0]);
      }
    } elseif ($type === 'elements') {
      $query->select(['element_uuid', 'element_type', 'page_uuid', 'session_id', 'user_id', 'created_at'])
        ->from('analytics_element_views');
    } else {
      $query->select(['session_id', 'user_id', 'ip_address', 'is_bot', 'created_at', 'last_activity', 'total_pages'])
        ->from('analytics_sessions');
    }

    if ($period !== 'all') {
      $query->andWhere(['>=', 'created_at', $this->getPeriodStartDate($period)]);
    }

    $data = $query->all();
    
    // Build CSV content in memory instead of streaming
    $csvContent = '';
    $fp = fopen('php://temp', 'r+');
    
    // Add header row
    if (!empty($data)) {
      fputcsv($fp, array_keys($data[0]));
      
      // Add data rows
      foreach ($data as $row) {
        fputcsv($fp, $row);
      }
    }
    
    rewind($fp);
    $csvContent = stream_get_contents($fp);
    fclose($fp);
    
    // Set response headers for file download
    Yii::$app->response->format = Response::FORMAT_RAW;
    Yii::$app->response->headers->set('Content-Type', 'text/csv');
    Yii::$app->response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    
    return $csvContent;
  }

  /**
   * Get the start date for a given period
   * @param string $period
   * @return string
   */
  private function getPeriodStartDate($period)
  {
    switch ($period) {
      case 'day':
        return date('Y-m-d 00:00:00');
      case 'week':
        return date('Y-m-d 00:00:00', strtotime('-7 days'));
      case 'month':
        return date('Y-m-d 00:00:00', strtotime('-30 days'));
      case 'year':
        return date('Y-m-d 00:00:00', strtotime('-365 days'));
      default:
        return date('Y-m-d 00:00:00', strtotime('-30 days'));
    }
  }
}