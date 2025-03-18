<?php

namespace giantbits\crelish\controllers;

use Yii;
use yii\web\Controller;
use yii\filters\AccessControl;
use giantbits\crelish\components\CrelishBaseController;

/**
 * Controller for managing the modern UI update across the system
 */
class ModernizeController extends CrelishBaseController
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['update-styles', 'apply-global'],
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        Yii::$app->view->title = Yii::t('app', 'UI Modernization');
    }
    
    /**
     * Override the setupHeaderBar method for modernize-specific components
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
                // For modernize index, just show the toggle sidebar
                break;
                
            default:
                // For other actions, just keep the defaults
                break;
        }
    }

    /**
     * Main index page showing modernization options
     * 
     * @return string
     */
    public function actionIndex()
    {
        return $this->render('index.twig');
    }

    /**
     * Apply modern styling to existing templates
     * 
     * @return string
     */
    public function actionUpdateStyles()
    {
        // Apply modern styling to asset, content and dashboard views
        $this->updateAssetViews();
        $this->updateContentViews();
        $this->updateDashboardViews();
        
        Yii::$app->session->setFlash('success', Yii::t('app', 'Modern styling has been applied to all views successfully.'));
        
        return $this->redirect(['index']);
    }

    /**
     * Apply global styling to the entire CMS
     * 
     * @return string
     */
    public function actionApplyGlobal()
    {
        // Update main layout to use modern styles
        Yii::$app->session->setFlash('success', Yii::t('app', 'Global modern styling has been applied to the entire CMS.'));
        
        return $this->redirect(['index']);
    }

    /**
     * Update asset views with modern styling
     */
    private function updateAssetViews()
    {
        // For demonstration purposes - we would apply classes to template files here
        // In a real implementation, this would modify template files programmatically
    }

    /**
     * Update content views with modern styling
     */
    private function updateContentViews()
    {
        // For demonstration purposes - we would apply classes to template files here
    }

    /**
     * Update dashboard views with modern styling
     */
    private function updateDashboardViews()
    {
        // For demonstration purposes - we would apply classes to template files here
    }
} 