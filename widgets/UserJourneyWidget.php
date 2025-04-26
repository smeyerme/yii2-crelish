<?php

namespace giantbits\crelish\widgets;

use Yii;
use yii\helpers\Html;
use yii\db\Query;

/**
 * Widget that shows a user's journey through the site
 */
class UserJourneyWidget extends CrelishDashboardWidget
{
    /**
     * @var string Session ID to track
     */
    public $sessionId = '';
    
    /**
     * @var int User ID to track
     */
    public $userId = '';
    
    /**
     * @var string IP address to track
     */
    public $ipAddress = '';
    
    /**
     * Initialize the widget
     */
    public function init()
    {
        parent::init();
        
        if (empty($this->title)) {
            $this->title = Yii::t('crelish', 'User Journey');
        }
        
        $this->description = Yii::t('crelish', 'Track a visitor\'s journey through the site');
        $this->size = 12;
        
        // Add session selector filter
        $this->filters['session'] = [
            'type' => 'select',
            'label' => Yii::t('crelish', 'Session'),
            'value' => $this->sessionId,
            'options' => $this->getSessionOptions()
        ];
    }
    
    /**
     * Get session options for filter
     * @return array
     */
    protected function getSessionOptions()
    {
        $sessions = (new Query())
            ->select(['session_id', 'ip_address', 'created_at'])
            ->from('analytics_sessions')
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(100)
            ->all();
            
        $options = ['' => Yii::t('crelish', 'Latest Session')];
        
        foreach ($sessions as $session) {
            $label = $session['ip_address'] . ' - ' . 
                Yii::$app->formatter->asDatetime($session['created_at']);
                
            $options[$session['session_id']] = $label;
        }
        
        return $options;
    }
    
    /**
     * Render widget content
     * @return string
     */
    protected function renderContent()
    {
        // Get journey data based on filters
        $journeyData = $this->getJourneyData();
        
        if (empty($journeyData)) {
            return Html::tag('div', 
                Yii::t('crelish', 'No journey data available for the selected filters'), 
                ['class' => 'alert alert-info']
            );
        }
        
        // Build journey timeline
        $html = '<div class="user-journey">';
        $html .= '<div class="journey-timeline">';
        
        foreach ($journeyData as $pageView) {
            $html .= '<div class="journey-item">';
            
            // Journey time
            $html .= Html::tag('div', 
                Yii::$app->formatter->asDatetime($pageView['created_at']), 
                ['class' => 'journey-time']
            );
            
            // Journey content
            $html .= '<div class="journey-content">';
            
            // Page info with reload indicator if applicable
            $html .= '<div class="journey-page">';
            $pageTitle = isset($pageView['title']) ? $pageView['title'] : $pageView['url'];
            
            // Add reload indicator if this page was visited multiple times
            if (!empty($pageView['reload_count'])) {
                $reloadIcon = '<i class="fa-sharp fa-regular fa-rotate me-2"></i>';
                $reloadCount = '<span class="badge bg-secondary ms-2">' . 
                    ($pageView['reload_count'] + 1) . 'x</span>';
                
                // If content changed during reload, use a different color
                if (!empty($pageView['content_changed'])) {
                    $reloadIcon = '<i class="fa-sharp fa-regular fa-rotate me-2 text-warning"></i>';
                }
                
                $pageTitle = $reloadIcon . Html::encode($pageTitle) . $reloadCount;
                $html .= Html::tag('h4', $pageTitle, ['class' => 'reload-indicator']);
            } else {
                $html .= Html::tag('h4', Html::encode($pageTitle));
            }
            
            // Display page type and URL
            $pageTypeHtml = Html::encode(ucfirst($pageView['page_type']));
            
            // Add the URL (shortened if too long)
            $url = $pageView['url'];
            $displayUrl = strlen($url) > 60 ? substr($url, 0, 57) . '...' : $url;
            $pageTypeHtml .= ' <span class="journey-url"><i class="fa-sharp fa-regular fa-link"></i> ' . 
                Html::encode($displayUrl) . '</span>';
                
            $html .= Html::tag('div', $pageTypeHtml, ['class' => 'text-muted page-meta']);
            
            // If there were reloads, show timestamp range
            if (!empty($pageView['reload_count']) && isset($pageView['timestamps'])) {
                $firstTime = min($pageView['timestamps']);
                $lastTime = max($pageView['timestamps']);
                
                $timeRange = Yii::$app->formatter->asTime($firstTime) . ' - ' . 
                    Yii::$app->formatter->asTime($lastTime);
                
                $html .= Html::tag('div', 
                    '<i class="fa-sharp fa-regular fa-clock me-1"></i>' . $timeRange, 
                    ['class' => 'text-muted reload-time-range']
                );
            }
            
            $html .= '</div>';
            
            // Elements viewed
            if (!empty($pageView['elements'])) {
                $html .= '<div class="journey-elements mt-2">';
                $html .= Html::tag('h5', Yii::t('crelish', 'Elements Viewed'));
                $html .= '<ul class="list-group">';
                
                foreach ($pageView['elements'] as $element) {
                    $html .= '<li class="list-group-item">';
                    $html .= '<div class="d-flex justify-content-between align-items-center">';
                    
                    // Element info
                    $html .= '<div>';
                    $elementTitle = isset($element['title']) ? $element['title'] : $element['element_uuid'];
                    $html .= Html::tag('strong', Html::encode($elementTitle));
                    $html .= Html::tag('div', Html::encode(ucfirst($element['element_type'])), ['class' => 'text-muted']);
                    $html .= '</div>';
                    
                    // View type badge
                    $viewType = isset($element['type']) ? $element['type'] : 'view';
                    $html .= Html::tag('span', Html::encode(ucfirst($viewType)), ['class' => 'badge bg-primary']);
                    
                    $html .= '</div>';
                    $html .= '</li>';
                }
                
                $html .= '</ul>';
                $html .= '</div>';
            }
            
            $html .= '</div>'; // end journey-content
            $html .= '</div>'; // end journey-item
        }
        
        $html .= '</div>'; // end journey-timeline
        $html .= '</div>'; // end user-journey
        
        return $html;
    }
    
    /**
     * Get journey data
     * @return array
     */
    protected function getJourneyData()
    {
        $query = (new Query())
            ->select([
                'analytics_page_views.page_uuid', 
                'analytics_page_views.page_type', 
                'analytics_page_views.url', 
                'analytics_page_views.created_at',
                'analytics_page_views.session_id'
            ])
            ->from('analytics_page_views')
            ->orderBy(['analytics_page_views.created_at' => SORT_ASC]);
            
        // Apply filters
        if (!empty($this->sessionId)) {
            $query->where(['analytics_page_views.session_id' => $this->sessionId]);
        } elseif (!empty($this->userId)) {
            $query->where(['analytics_page_views.user_id' => $this->userId]);
        } elseif (!empty($this->ipAddress)) {
            $query->where(['analytics_page_views.ip_address' => $this->ipAddress]);
        } else {
            // Default: get latest session
            $latestSession = (new Query())
                ->select('session_id')
                ->from('analytics_sessions')
                ->orderBy(['created_at' => SORT_DESC])
                ->limit(1)
                ->scalar();
                
            if ($latestSession) {
                $query->where(['analytics_page_views.session_id' => $latestSession]);
                $this->sessionId = $latestSession;
            }
        }
        
        $rawPageViews = $query->all();
        
        if (empty($rawPageViews)) {
            return [];
        }
        
        // Group page views by URL
        $groupedPageViews = [];
        $currentUrl = null;
        $currentGroup = null;
        
        foreach ($rawPageViews as $pageView) {
            // If this is the first item or we have a new URL
            if ($currentUrl !== $pageView['url']) {
                // Save the previous group if exists
                if ($currentGroup !== null) {
                    $groupedPageViews[] = $currentGroup;
                }
                
                // Start a new group
                $currentUrl = $pageView['url'];
                $currentGroup = $pageView;
                $currentGroup['reload_count'] = 0;
                $currentGroup['timestamps'] = [$pageView['created_at']];
                
            } else {
                // Same URL as before - count as reload
                $currentGroup['reload_count']++;
                $currentGroup['timestamps'][] = $pageView['created_at'];
                
                // If the page_uuid is different, this could be a reload that changed content
                if ($currentGroup['page_uuid'] !== $pageView['page_uuid']) {
                    $currentGroup['content_changed'] = true;
                }
            }
        }
        
        // Add the last group
        if ($currentGroup !== null) {
            $groupedPageViews[] = $currentGroup;
        }
        
        if (empty($groupedPageViews)) {
            return [];
        }
        
        // Enrich grouped page views with element views
        foreach ($groupedPageViews as &$pageView) {
            // Get elements for all page views with this URL (for all timestamps)
            $pageUuids = [];
            $timestamps = $pageView['timestamps'];
            
            // If this is a reload, we need to get all the page UUIDs that match this URL
            if ($pageView['reload_count'] > 0) {
                $pageUuids = (new Query())
                    ->select('page_uuid')
                    ->from('analytics_page_views')
                    ->where([
                        'and',
                        ['url' => $pageView['url']],
                        ['session_id' => $pageView['session_id']],
                        ['between', 'created_at', min($timestamps), max($timestamps)]
                    ])
                    ->column();
            } else {
                $pageUuids = [$pageView['page_uuid']];
            }
            
            $pageView['elements'] = (new Query())
                ->select([
                    'element_uuid', 
                    'element_type', 
                    'type', 
                    'created_at'
                ])
                ->from('analytics_element_views')
                ->where([
                    'and',
                    ['in', 'page_uuid', $pageUuids],
                    ['session_id' => $pageView['session_id']]
                ])
                ->orderBy(['created_at' => SORT_ASC])
                ->all();
                
            // Try to get page title
            try {
                $modelClass = 'app\workspace\models\\' . ucfirst($pageView['page_type']);
                if (class_exists($modelClass)) {
                    $pageModel = call_user_func($modelClass . '::find')
                        ->where(['uuid' => $pageView['page_uuid']])
                        ->one();
                        
                    if ($pageModel && isset($pageModel['systitle'])) {
                        $pageView['title'] = $pageModel['systitle'];
                    }
                }
            } catch (\Exception $e) {
                // Skip title enrichment if model not found
            }
            
            // Enrich elements with titles
            foreach ($pageView['elements'] as &$element) {
                try {
                    $modelClass = 'app\workspace\models\\' . ucfirst($element['element_type']);
                    if (class_exists($modelClass)) {
                        $elementModel = call_user_func($modelClass . '::find')
                            ->where(['uuid' => $element['element_uuid']])
                            ->one();
                            
                        if ($elementModel && isset($elementModel['systitle'])) {
                            $element['title'] = $elementModel['systitle'];
                        }
                    }
                } catch (\Exception $e) {
                    // Skip title enrichment if model not found
                }
            }
        }
        
        return $groupedPageViews;
    }
}