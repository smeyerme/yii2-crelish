<?php

namespace giantbits\crelish\widgets;

use Yii;
use yii\helpers\Html;
use yii\db\Query;
use yii\helpers\Url;

/**
 * Widget that shows top content elements
 */
class TopElementsWidget extends CrelishDashboardWidget
{
  /**
   * @var string Period filter
   */
  public $period = 'month';

  /**
   * @var string Element type filter
   */
  public $elementType = '';

  /**
   * @var int Limit for results
   */
  public $limit = 10;

  /**
   * Initialize the widget
   */
  public function init()
  {
    parent::init();

    if (empty($this->title)) {
      $this->title = Yii::t('crelish', 'Top Content Elements');
    }

    $this->description = Yii::t('crelish', 'Shows most viewed content elements');

    // Add period filter
    $this->filters['period'] = [
      'type' => 'select',
      'label' => Yii::t('crelish', 'Time Period'),
      'value' => $this->period,
      'options' => [
        'day' => Yii::t('crelish', 'Last 24 Hours'),
        'week' => Yii::t('crelish', 'Last 7 Days'),
        'month' => Yii::t('crelish', 'Last 30 Days'),
        'year' => Yii::t('crelish', 'Last Year'),
        'all' => Yii::t('crelish', 'All Time')
      ]
    ];

    // Add element type filter
    $this->filters['elementType'] = [
      'type' => 'select',
      'label' => Yii::t('crelish', 'Element Type'),
      'value' => $this->elementType,
      'options' => $this->getElementTypeOptions()
    ];

    // Add limit filter
    $this->filters['limit'] = [
      'type' => 'select',
      'label' => Yii::t('crelish', 'Results Limit'),
      'value' => $this->limit,
      'options' => [
        5 => '5',
        10 => '10',
        20 => '20',
        50 => '50',
        100 => '100'
      ]
    ];
  }

  /**
   * Get element type options for filter
   * @return array
   */
  protected function getElementTypeOptions()
  {
    $elementTypes = (new Query())
      ->select(['element_type'])
      ->from('analytics_element_views')
      ->groupBy(['element_type'])
      ->all();

    $options = ['' => Yii::t('crelish', 'All Types')];

    foreach ($elementTypes as $type) {
      $options[$type['element_type']] = ucfirst($type['element_type']);
    }

    $options['download'] = Yii::t('crelish', 'Downloads');
    $options['list'] = Yii::t('crelish', 'List Views');
    $options['detail'] = Yii::t('crelish', 'Detail Views');

    return $options;
  }

  /**
   * Render widget content
   * @return string
   */
  protected function renderContent()
  {
    // Get top elements data
    $data = $this->getTopElementsData();

    if (empty($data)) {
      return Html::tag('div',
        Yii::t('crelish', 'No data available for the selected filters'),
        ['class' => 'alert alert-info']
      );
    }

    // Build HTML output
    $html = '';

    // Add pie chart for content type distribution
    $html .= $this->renderContentTypeDistribution($data);

    // Add table with detailed data
    $html .= '<div class="table-responsive mt-4">';
    $html .= '<table class="table table-striped">';

    // Create table header
    $html .= '<thead><tr>';
    $html .= Html::tag('th', Yii::t('crelish', 'Element'));
    $html .= Html::tag('th', Yii::t('crelish', 'Type'));

    // Add file type column for downloads
    if ($this->elementType === 'download') {
      $html .= Html::tag('th', Yii::t('crelish', 'File Type'));
    }

    // Add view type column if showing all types
    if (empty($this->elementType)) {
      $html .= Html::tag('th', Yii::t('crelish', 'View Type'));
    }

    $html .= Html::tag('th', Yii::t('crelish', 'Views'));
    $html .= '</tr></thead>';

    // Create table body
    $html .= '<tbody>';

    foreach ($data as $element) {
      $html .= '<tr>';

      // Element title/ID
      $title = isset($element['title']) ? $element['title'] : $element['element_uuid'];
      $html .= Html::tag('td', Html::encode($title));

      // Element type
      $html .= Html::tag('td', Html::encode(ucfirst($element['element_type'])));

      // File type column for downloads
      if ($this->elementType === 'download') {
        $fileType = isset($element['file_type']) ? $element['file_type'] : 'Unknown';
        $html .= Html::tag('td', Html::encode($fileType));
      }

      // View type column if showing all types
      if (empty($this->elementType)) {
        $viewType = isset($element['view_type']) ? $element['view_type'] : 'view';
        $html .= Html::tag('td', Html::encode(ucfirst($viewType)));
      }

      // Views count
      $html .= Html::tag('td', Html::encode($element['views']));

      $html .= '</tr>';
    }

    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>';

    return $html;
  }

  /**
   * Render pie chart for content type distribution
   * @param array $data The element data
   * @return string HTML content
   */
  protected function renderContentTypeDistribution($data)
  {
    // Calculate the distribution by element type
    $distribution = [];
    $totalViews = 0;

    foreach ($data as $element) {
      $type = ucfirst($element['element_type']);
      if (!isset($distribution[$type])) {
        $distribution[$type] = 0;
      }
      $distribution[$type] += (int)$element['views'];
      $totalViews += (int)$element['views'];
    }

    // Sort by number of views (descending)
    arsort($distribution);

    // Create chart container
    $chartContainerId = $this->id . '-type-chart';

    $html = '<div class="row">';

    // Chart container (left side)
    $html .= '<div class="col-md-4">';
    $html .= Html::tag('div', Html::tag('canvas', '', ['id' => $chartContainerId]),
      ['class' => 'chart-container', 'style' => 'height: 250px;']);
    $html .= '</div>';

    // Stats column (right side)
    $html .= '<div class="col-md-8">';
    $html .= '<div class="summary-stat">';
    $html .= Html::tag('h4', Yii::t('crelish', 'Content Type Distribution'));

    // Add a small table with percentages
    $html .= '<div class="table-responsive">';
    $html .= '<table class="table table-sm">';
    $html .= '<thead><tr>';
    $html .= Html::tag('th', Yii::t('crelish', 'Content Type'));
    $html .= Html::tag('th', Yii::t('crelish', 'Views'));
    $html .= Html::tag('th', Yii::t('crelish', 'Percentage'));
    $html .= '</tr></thead>';
    $html .= '<tbody>';

    foreach ($distribution as $type => $views) {
      $percentage = $totalViews > 0 ? round(($views / $totalViews) * 100) : 0;

      $html .= '<tr>';
      $html .= Html::tag('td', Html::encode($type));
      $html .= Html::tag('td', Html::encode($views));
      $html .= Html::tag('td', Html::encode($percentage . '%'));
      $html .= '</tr>';
    }

    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>'; // end table-responsive

    $html .= '</div>'; // end summary-stat
    $html .= '</div>'; // end col-md-6
    $html .= '</div>'; // end row

    // Prepare chart data
    $labels = array_keys($distribution);
    $values = array_values($distribution);

    // Generate colors for the chart
    $backgroundColors = $this->generateChartColors(count($distribution));

    $chartData = [
      'labels' => $labels,
      'datasets' => [
        [
          'data' => $values,
          'backgroundColor' => $backgroundColors,
          'borderWidth' => 0
        ]
      ]
    ];

    $chartDataJson = \yii\helpers\Json::encode($chartData);

    // Add chart initialization JavaScript
    $js = <<<JS
(function() {
    var ctx = document.getElementById('{$chartContainerId}').getContext('2d');
    
    // Destroy existing chart if exists
    if (window.dashboardCharts && window.dashboardCharts['{$chartContainerId}']) {
        window.dashboardCharts['{$chartContainerId}'].destroy();
    }
    
    // Initialize chart object storage if not exists
    if (!window.dashboardCharts) {
        window.dashboardCharts = {};
    }
    
    // Detect dark mode based on various theme attributes
    var isDarkMode = document.documentElement.getAttribute('data-theme') === 'dark' || 
                     document.documentElement.getAttribute('data-bs-theme') === 'dark' ||
                     document.body.getAttribute('data-bs-theme') === 'dark' ||
                     document.body.classList.contains('dark-mode') ||
                     document.documentElement.classList.contains('dark-mode');
    
    // Set colors based on theme
    var fontColor = isDarkMode ? '#e1e1e1' : '#555';
    
    // Create chart data with theme colors
    var chartData = {$chartDataJson};
    
    // Create new chart
    window.dashboardCharts['{$chartContainerId}'] = new Chart(ctx, {
        type: 'pie',
        data: chartData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        color: fontColor,
                        padding: 10,
                        boxWidth: 12,
                        font: {
                            weight: 'bold'
                        }
                    }
                },
                tooltip: {
                    backgroundColor: isDarkMode ? '#1e2430' : '#fff',
                    titleColor: isDarkMode ? '#fff' : '#000',
                    bodyColor: isDarkMode ? '#e1e1e1' : '#333',
                    borderColor: isDarkMode ? 'rgba(255, 255, 255, 0.2)' : 'rgba(0, 0, 0, 0.1)',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: function(context) {
                            var label = context.label || '';
                            var value = context.raw || 0;
                            var percentage = context.parsed || 0;
                            var total = context.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                            percentage = Math.round((value / total) * 100);
                            return label + ': ' + value + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
})();
JS;

    $html .= Html::script($js);

    return $html;
  }

  /**
   * Generate a nice color palette for the chart
   * @param int $count Number of colors needed
   * @return array Array of color codes
   */
  protected function generateChartColors($count)
  {
    // Predefined palette of visually distinct colors that work well together
    $palette = [
      '#4CAF50', // Green
      '#2196F3', // Blue
      '#9C27B0', // Purple
      '#F44336', // Red
      '#FF9800', // Orange
      '#FFEB3B', // Yellow
      '#607D8B', // Blue Grey
      '#009688', // Teal
      '#E91E63', // Pink
      '#3F51B5', // Indigo
      '#CDDC39', // Lime
      '#795548', // Brown
      '#00BCD4', // Cyan
      '#FF5722', // Deep Orange
      '#8BC34A', // Light Green
      '#673AB7', // Deep Purple
      '#FFC107', // Amber
      '#03A9F4', // Light Blue
      '#9E9E9E', // Grey
      '#FF4081', // Pink Accent
    ];

    // If we need more colors than are in the palette, generate additional ones
    if ($count > count($palette)) {
      for ($i = count($palette); $i < $count; $i++) {
        $palette[] = 'hsl(' . (($i * 37) % 360) . ', 70%, 60%)';
      }
    }

    // Return only the needed colors
    return array_slice($palette, 0, $count);
  }

  /**
   * Get top elements data
   * @return array
   */
  protected function getTopElementsData()
  {
    $params = [
      'period' => $this->period,
      'limit' => $this->limit
    ];

    // Only add type parameter if specified
    if (!empty($this->elementType)) {
      $params['type'] = $this->elementType;
    }

    // Use analytics component to get data
    $data = Yii::$app->crelishAnalytics->getTopElements(
      $params['period'],
      $params['limit'],
      isset($params['type']) ? $params['type'] : null
    );

    // Enrich data with titles
    foreach ($data as &$element) {
      // For assets (especially downloads)
      if ($element['element_type'] === 'asset') {
        try {
          $assetModel = \app\workspace\models\Asset::findOne($element['element_uuid']);
          if ($assetModel) {
            $element['title'] = $assetModel->title ?? $assetModel->fileName ?? ('Asset: ' . $element['element_uuid']);
            $element['file_type'] = $assetModel->mime ?? 'Unknown';
            $element['file_size'] = $assetModel->size ?? 0;
          } else {
            $element['title'] = 'Asset: ' . $element['element_uuid'];
          }
        } catch (\Exception $e) {
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
        }
      }

      // Add type info for display in the UI
      $element['view_type'] = $element['type'] ?? 'view';
    }

    return $data;
  }
}