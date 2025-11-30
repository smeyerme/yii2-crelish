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
    
    // Make sure Chart.js is loaded
    $this->view->registerJsFile('https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js', ['position' => \yii\web\View::POS_HEAD]);
    
    // Register dashboard-specific CSS
    $this->view->registerCss($this->getDashboardCss());
  }

  /**
   * Override the setupHeaderBar method for dashboard-specific components
   */
  protected function setupHeaderBar()
  {
    // Default left components for all actions
    $this->view->params['headerBarLeft'] = ['toggle-sidebar'];

    // Default right components (empty by default)
    $this->view->params['headerBarRight'] = [];

    // Set specific components based on action
    $action = $this->action ? $this->action->id : null;

    switch ($action) {
      case 'index':
        // For dashboard index, just show the toggle sidebar
        $this->view->params['headerBarLeft'][] = ['title', Yii::t('crelish', 'Website Analytics')];
        break;
        
      case 'sessions':
        // For sessions view
        $this->view->params['headerBarLeft'][] = ['title', Yii::t('crelish', 'User Sessions')];
        break;

      default:
        // For other actions, just keep the defaults
        break;
    }
  }

  /**
   * Dashboard index
   */
  public function actionIndex()
  {
    $period = Yii::$app->request->get('period', 'month');
    $excludeBots = Yii::$app->request->get('exclude_bots', 1);
    $uniqueVisitors = Yii::$app->request->get('unique_visitors', 0);

    return $this->render('index', [
      'period' => $period,
      'excludeBots' => $excludeBots,
      'uniqueVisitors' => $uniqueVisitors
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
    $uniqueVisitors = Yii::$app->request->get('unique_visitors', 0);

    return Yii::$app->crelishAnalytics->getPageViewStats($period, (bool)$excludeBots, (bool)$uniqueVisitors);
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
    $uniqueVisitors = Yii::$app->request->get('unique_visitors', 0);

    $pages = Yii::$app->crelishAnalytics->getTopPages($period, $limit, (bool)$excludeBots, (bool)$uniqueVisitors);

    // Enrich with page titles
    foreach ($pages as &$page) {

      // Try to get page title from database
      $modelClass = \giantbits\crelish\components\CrelishModelResolver::getModelClass($page['page_type']);
      $pageModel = $modelClass::find()
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
          if (\giantbits\crelish\components\CrelishModelResolver::modelExists($element['element_type'])) {
            $modelClass = \giantbits\crelish\components\CrelishModelResolver::getModelClass($element['element_type']);
            $elementModel = $modelClass::find()
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
    $uniqueVisitors = Yii::$app->request->get('unique_visitors', 0);
    $type = Yii::$app->request->get('type', 'page_views');

    $filename = 'analytics_' . $type . '_' . date('Y-m-d') . '.csv';

    $query = (new \yii\db\Query());

    if ($type === 'page_views') {
      // If unique visitors is enabled and we're exporting page views
      if ($uniqueVisitors) {
        // Get a list of unique IP + session_id combinations
        $query->select(['page_uuid', 'page_type', 'url', 'session_id', 'user_id', 'ip_address', 'created_at'])
          ->from('analytics_page_views')
          ->groupBy(['ip_address', 'session_id', 'page_uuid']);
      } else {
        // Regular page views export
        $query->select(['page_uuid', 'page_type', 'url', 'session_id', 'user_id', 'ip_address', 'created_at'])
          ->from('analytics_page_views');
      }

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
  
  /**
   * Dashboard view with modular widgets
   */
  public function actionDashboard()
  {
    $period = Yii::$app->request->get('period', 'month');
    $excludeBots = Yii::$app->request->get('exclude_bots', 1);
    $uniqueVisitors = Yii::$app->request->get('unique_visitors', 0);
    
    // Get widgets from dashboard manager
    $dashboardManager = Yii::$app->dashboardManager;
    
    $topWidgets = $dashboardManager->getWidgets('top');
    $leftWidgets = $dashboardManager->getWidgets('left');
    $rightWidgets = $dashboardManager->getWidgets('right');
    $bottomWidgets = $dashboardManager->getWidgets('bottom');
    
    // Prepare available widgets for adding
    $availableWidgets = [
        'pageviews' => Yii::t('crelish', 'Page Views'),
        'uniquevisitors' => Yii::t('crelish', 'Unique Visitors'),
        'contenttypes' => Yii::t('crelish', 'Content Types'),
        'topelements' => Yii::t('crelish', 'Top Elements'),
        'userjourney' => Yii::t('crelish', 'User Journey'),
        'contentperformance' => Yii::t('crelish', 'Content Performance')
    ];
    
    // Render the dashboard view
    if (isset(Yii::$app->view->renderers['twig'])) {
        // Use Twig for rendering
        return $this->render('dashboard.twig', [
            'period' => $period,
            'excludeBots' => $excludeBots,
            'uniqueVisitors' => $uniqueVisitors,
            'topWidgets' => $topWidgets,
            'leftWidgets' => $leftWidgets,
            'rightWidgets' => $rightWidgets,
            'bottomWidgets' => $bottomWidgets,
            'availableWidgets' => $availableWidgets
        ]);
    } else {
        // Fallback to PHP rendering
        return $this->render('dashboard', [
            'period' => $period,
            'excludeBots' => $excludeBots,
            'uniqueVisitors' => $uniqueVisitors,
            'topWidgets' => $topWidgets,
            'leftWidgets' => $leftWidgets,
            'rightWidgets' => $rightWidgets,
            'bottomWidgets' => $bottomWidgets,
            'availableWidgets' => $availableWidgets
        ]);
    }
  }
  
  /**
   * Add widget to dashboard
   */
  public function actionAddWidget()
  {
    Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
    
    $section = Yii::$app->request->post('section');
    $type = Yii::$app->request->post('type');
    $options = Yii::$app->request->post('options', []);
    
    if (!$section || !$type) {
        return ['success' => false, 'message' => Yii::t('crelish', 'Missing required parameters')];
    }
    
    $result = Yii::$app->dashboardManager->addWidget($section, $type, $options);
    
    return [
        'success' => $result,
        'message' => $result ? 
            Yii::t('crelish', 'Widget added successfully') : 
            Yii::t('crelish', 'Failed to add widget')
    ];
  }
  
  /**
   * Remove widget from dashboard
   */
  public function actionRemoveWidget()
  {
    Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
    
    $section = Yii::$app->request->post('section');
    $index = Yii::$app->request->post('index');
    
    if (!$section || $index === null) {
        return ['success' => false, 'message' => Yii::t('crelish', 'Missing required parameters')];
    }
    
    $result = Yii::$app->dashboardManager->removeWidget($section, (int)$index);
    
    return [
        'success' => $result,
        'message' => $result ? 
            Yii::t('crelish', 'Widget removed successfully') : 
            Yii::t('crelish', 'Failed to remove widget')
    ];
  }
  
  /**
   * Get widget data via AJAX
   */
  public function actionGetWidgetData()
  {
    Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
    
    $widgetId = Yii::$app->request->get('widget_id');
    $filters = Yii::$app->request->get('filters', []);
    
    if (!$widgetId) {
        return ['success' => false, 'message' => Yii::t('crelish', 'Missing widget ID')];
    }
    
    // Find the widget in the dashboard configuration
    $widget = null;
    $dashboardManager = Yii::$app->dashboardManager;
    
    foreach (['top', 'left', 'right', 'bottom'] as $section) {
        foreach ($dashboardManager->getWidgets($section) as $w) {
            if ($w->id === $widgetId) {
                $widget = $w;
                break 2;
            }
        }
    }
    
    if (!$widget) {
        return ['success' => false, 'message' => Yii::t('crelish', 'Widget not found')];
    }
    
    // Apply filters to widget
    foreach ($filters as $key => $value) {
        if (property_exists($widget, $key)) {
            $widget->$key = $value;
        }
    }
    
    // Get widget data
    $data = $widget->getWidgetData();
    
    return [
        'success' => true,
        'data' => $data
    ];
  }
  
  /**
   * User sessions view
   */
  public function actionSessions()
  {
    $period = Yii::$app->request->get('period', 'month');
    $excludeBots = Yii::$app->request->get('exclude_bots', 1);
    $page = Yii::$app->request->get('page', 1);
    $pageSize = Yii::$app->request->get('per_page', 20);
    $sessionId = Yii::$app->request->get('session_id', '');
    
    return $this->render('sessions.twig', [
      'period' => $period,
      'excludeBots' => $excludeBots,
      'page' => $page,
      'pageSize' => $pageSize,
      'sessionId' => $sessionId
    ]);
  }
  
  /**
   * Get session data as JSON for chart and table
   */
  public function actionGetSessionsData()
  {
    Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
    
    $period = Yii::$app->request->get('period', 'month');
    $excludeBots = Yii::$app->request->get('exclude_bots', 1);
    $page = intval(Yii::$app->request->get('page', 1));
    $pageSize = intval(Yii::$app->request->get('per_page', 20));
    
    // Get session stats by day for chart
    $dateExpression = $this->getDateExpressionForPeriod($period);
    $chartQuery = (new \yii\db\Query())
      ->select([
        'date' => $dateExpression,
        'count' => 'COUNT(*)'
      ])
      ->from('analytics_sessions');
    
    if ($excludeBots) {
      $chartQuery->where(['is_bot' => 0]);
    }
    
    if ($period !== 'all') {
      $chartQuery->andWhere(['>=', 'created_at', $this->getPeriodStartDate($period)]);
    }
    
    $chartData = $chartQuery
      ->groupBy(['date'])
      ->orderBy(['date' => SORT_ASC])
      ->all();
    
    // Get sessions list with pagination
    $sessionQuery = (new \yii\db\Query())
      ->select([
        'analytics_sessions.session_id',
        'analytics_sessions.user_id',
        'analytics_sessions.ip_address',
        'analytics_sessions.created_at',
        'analytics_sessions.last_activity',
        'analytics_sessions.total_pages',
        'analytics_sessions.first_url',
        'total_elements' => '(SELECT COUNT(*) FROM analytics_element_views WHERE analytics_element_views.session_id = analytics_sessions.session_id)'
      ])
      ->from('analytics_sessions');
      
    if ($excludeBots) {
      $sessionQuery->where(['is_bot' => 0]);
    }
    
    if ($period !== 'all') {
      $sessionQuery->andWhere(['>=', 'created_at', $this->getPeriodStartDate($period)]);
    }
    
    // Get total count for pagination
    $totalCount = $sessionQuery->count();
    
    // Apply pagination
    $sessionsList = $sessionQuery
      ->orderBy(['created_at' => SORT_DESC])
      ->offset(($page - 1) * $pageSize)
      ->limit($pageSize)
      ->all();
    
    // Enhance session data with additional information
    foreach ($sessionsList as &$session) {
      // Format dates for display
      $session['created_at_formatted'] = Yii::$app->formatter->asDatetime($session['created_at']);
      $session['last_activity_formatted'] = Yii::$app->formatter->asDatetime($session['last_activity']);
      
      // Calculate session duration
      $startTime = strtotime($session['created_at']);
      $endTime = strtotime($session['last_activity']);
      $session['duration'] = $endTime - $startTime;
      $session['duration_formatted'] = Yii::$app->formatter->asDuration($session['duration']);
      
      // Get first page title if available
      try {
        // Find the first page view for this session
        $firstPageView = (new \yii\db\Query())
          ->select(['page_uuid', 'page_type'])
          ->from('analytics_page_views')
          ->where(['session_id' => $session['session_id']])
          ->orderBy(['created_at' => SORT_ASC])
          ->limit(1)
          ->one();
          
        if ($firstPageView) {
          if (\giantbits\crelish\components\CrelishModelResolver::modelExists($firstPageView['page_type'])) {
            $modelClass = \giantbits\crelish\components\CrelishModelResolver::getModelClass($firstPageView['page_type']);
            $pageModel = $modelClass::find()
              ->where(['uuid' => $firstPageView['page_uuid']])
              ->one();

            if ($pageModel && isset($pageModel['systitle'])) {
              $session['first_page_title'] = $pageModel['systitle'];
              $session['first_page_type'] = $firstPageView['page_type'];
            }
          }
        }
      } catch (\Exception $e) {
        // Skip title enrichment if model not found
      }
    }
    
    return [
      'chartData' => $chartData,
      'sessions' => $sessionsList,
      'pagination' => [
        'total' => $totalCount,
        'page' => $page,
        'pageSize' => $pageSize,
        'pageCount' => ceil($totalCount / $pageSize)
      ]
    ];
  }
  
  /**
   * Get the date expression for a given period for SQL grouping
   * @param string $period
   * @return \yii\db\Expression
   */
  private function getDateExpressionForPeriod($period)
  {
    switch ($period) {
      case 'day':
        return new \yii\db\Expression('DATE_FORMAT(created_at, "%Y-%m-%d %H:00")');
      case 'week':
        return new \yii\db\Expression('DATE_FORMAT(created_at, "%Y-%m-%d")');
      case 'month':
        return new \yii\db\Expression('DATE_FORMAT(created_at, "%Y-%m-%d")');
      case 'year':
        return new \yii\db\Expression('DATE_FORMAT(created_at, "%Y-%m")');
      default:
        return new \yii\db\Expression('DATE_FORMAT(created_at, "%Y-%m-%d")');
    }
  }
  
  /**
   * Render a widget via AJAX
   */
  public function actionRenderWidget()
  {
    $widgetName = Yii::$app->request->get('widget');
    $widgetParams = Yii::$app->request->get();
    
    // Remove the 'widget' parameter to avoid passing it to the widget
    unset($widgetParams['widget']);
    
    // Check if widget class exists
    $widgetClass = 'giantbits\\crelish\\widgets\\' . $widgetName;
    if (!class_exists($widgetClass)) {
      return Yii::t('crelish', 'Widget not found: {widget}', ['widget' => $widgetName]);
    }
    
    // Render the widget
    try {
      return $widgetClass::widget($widgetParams);
    } catch (\Exception $e) {
      Yii::error('Error rendering widget: ' . $e->getMessage());
      return Yii::t('crelish', 'Error rendering widget: {error}', ['error' => $e->getMessage()]);
    }
  }
  
  /**
   * Get CSS for dashboard
   * @return string
   */
  private function getDashboardCss()
  {
    return <<<CSS
/* Dashboard specific styles */
.dashboard-widget {
    position: relative;
}

.widget-loading {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.7);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10;
}

.journey-timeline {
    position: relative;
    padding-left: 30px;
}

.journey-item {
    position: relative;
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-left: 2px solid #007bff;
    padding-left: 20px;
}

.journey-item:last-child {
    border-left: none;
}

.journey-time {
    position: absolute;
    left: -150px;
    width: 130px;
    text-align: right;
    font-size: 0.85rem;
    color: #6c757d;
}

.journey-item:before {
    content: '';
    position: absolute;
    left: -8px;
    top: 0;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #007bff;
}

.summary-stat {
    margin-bottom: 20px;
    text-align: center;
}

.summary-stat h4 {
    color: var(--bs-body-color);
    margin-bottom: 0.5rem;
    font-size: 1rem;
}

.stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: var(--bs-primary);
}

.chart-container {
    position: relative;
    width: 100%;
}

/* Dark mode compatibility */
html[data-bs-theme="dark"] .widget-loading {
    background: rgba(0, 0, 0, 0.5);
}

/* Sessions view styles */
.sessions-chart-container {
    position: relative;
    width: 100%;
    min-height: 300px;
    margin-bottom: 2rem;
}

.sessions-chart {
    margin-bottom: 2rem;
}

.sessions-table {
    cursor: pointer;
}

.sessions-table tbody tr:hover {
    background-color: rgba(13, 110, 253, 0.05);
}

html[data-bs-theme="dark"] .sessions-table tbody tr:hover {
    background-color: rgba(77, 137, 249, 0.1) !important;
}

.session-journey-container {
    transition: all 0.3s ease;
    max-height: 0;
    overflow: hidden;
}

.session-journey-container.visible {
    max-height: 2000px;
}

.chart-point {
    cursor: pointer;
}

.chart-tooltip {
    background-color: #fff;
    border: 1px solid #ddd;
    padding: 8px;
    border-radius: 4px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    pointer-events: none;
    z-index: 100;
}

html[data-bs-theme="dark"] .chart-tooltip {
    background-color: #1e2430;
    border-color: #2c3440;
    color: #e1e1e1;
}
CSS;
  }
}