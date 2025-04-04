<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishDataProvider;
use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishBaseController;
use yii\filters\AccessControl;
use function _\map;
use Yii;

class PageController extends CrelishBaseController
{
  public $layout = 'crelish.twig';

  public function behaviors()
  {
    return [
      'access' => [
        'class' => AccessControl::class,
        'only' => ['create', 'index', 'delete'],
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
   * [init description]
   * @return [type] [description]
   */
  public function init()
  {
    parent::init();
    
    $this->uuid = (!empty(\Yii::$app->getRequest()
      ->getQueryParam('uuid'))) ? \Yii::$app->getRequest()
      ->getQueryParam('uuid') : null;

    $this->ctype = 'page';

    \Yii::$app->view->registerJs('
      $(document).on("pjax:complete" , function(event) {
        $(".scrollable").animate({ scrollTop: "0" });
      });
    ', \yii\web\View::POS_LOAD);
  }
  
  /**
   * Called before the action is executed
   * 
   * @param \yii\base\Action $action the action to be executed
   * @return bool whether the action should continue to be executed
   */
  public function beforeAction($action)
  {
    if (!parent::beforeAction($action)) {
      return false;
    }
    
    // Set up the header bar components now that we know the action
    $this->setupHeaderBar();
    
    return true;
  }

  /**
   * Override the setupHeaderBar method to use page-specific components
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
        // For page index, use search and create buttons
        $this->view->params['headerBarLeft'][] = 'search';
        $this->view->params['headerBarRight'] = ['delete', 'create'];
        break;
        
      case 'create':
        // For create actions, add back button and save buttons (without delete)
        $this->view->params['headerBarLeft'][] = 'back-button';
        $this->view->params['headerBarRight'] = [['save', true, false]]; // Show save and return, no delete
        break;
        
      case 'update':
        // For update actions, add back button and save buttons (with delete)
        $this->view->params['headerBarLeft'][] = 'back-button';
        $this->view->params['headerBarRight'] = [['save', true, true]]; // Show save and return, with delete
        break;
        
      default:
        // For other actions, just keep the defaults
        break;
    }
  }

  /**
   * [actionIndex description]
   * @return [type] [description]
   */
  public function actionIndex()
  {
    $filter = null;

    if (!empty($_POST['selection'])) {
      foreach ($_POST['selection'] as $selection) {
        $delModel = new CrelishDynamicModel( ['ctype' => $this->ctype, 'uuid' => $selection]);
        $delModel->delete();
      }
    }
    
    // Handle content filtering
    $searchTerm = $this->handleSessionAndQueryParams('cr_content_filter');
    if (!empty($searchTerm)) {
      $filter = ['freesearch' => $searchTerm];
    }

    if(empty($_GET['sort'])) {
      $_GET['sort'] = 'systitle';
    }

	  $modelProvider = new CrelishDataProvider($this->ctype, ['filter' => $filter]);

    $checkCol = [
      [
        'class' => 'giantbits\crelish\components\CrelishCheckboxColumn',
      ]
    ];

    //$columns = array_merge($checkCol, $modelProvider->columns);
    $columns = array_merge($checkCol, [
      ['attribute' => 'systitle', 'label' => Yii::t('app', 'Systitle')],
      ['attribute' => 'slug',  'label' => Yii::t('app', 'Slug'), 'value' => function ($model) {
        return '/' . $model->slug;
      }],
      ['attribute' => 'state'],
      [
        'attribute' => 'updated',
        'label' => Yii::t('app', 'Updated'),
        'format' => 'date',
      ],
    ]);

    $columns = map($columns, function ($item) {

      if (key_exists('attribute', $item) && $item['attribute'] === 'state') {
        $item['format'] = 'raw';
        $item['label'] = 'Status';
        $item['value'] = function ($data) {
          $state = match ($data['state']) {
            1 => 'Draft',
            2 => 'Online',
            3 => 'Archived',
            default => 'Offline',
          };;

          return $state;
        };
      }

      return $item;
    });

    $rowOptions = function ($model, $key, $index, $grid) {
      return ['onclick' => 'location.href="update?ctype=' . $this->ctype . '&uuid=' . $model['uuid'] . '";'];
    };

    return $this->render('content.twig', [
      'dataProvider' => $modelProvider->getProvider(),
      'filterProvider' => $modelProvider->getFilters(),
      'columns' => $columns,
      'ctype' => $this->ctype,
      'rowOptions' => $rowOptions
    ]);
  }

  /**
   * [actionCreate description]
   * @return [type] [description]
   */
  public function actionCreate()
  {
    $content = $this->buildForm();
		
		return $this->render('create.twig', [
      'content' => $content,
      'ctype' => $this->ctype,
      'uuid' => $this->uuid,
    ]);
  }

  /**
   * [actionUpdate description]
   * @return [type] [description]
   */
  public function actionUpdate()
  {
    $content = $this->buildForm();
		
		\Yii::$app->view->params['model'] = $this->model;

    return $this->render('create.twig', [
      'content' => $content,
      'ctype' => $this->ctype,
      'uuid' => $this->uuid,
    ]);
  }

  /**
   * [actionDelete description]
   * @return [type] [description]
   */
  public function actionDelete()
  {
    $ctype = \Yii::$app->request->get('ctype');
    $uuid = \Yii::$app->request->get('uuid');

    $model = new CrelishDynamicModel( ['ctype' => $ctype, 'uuid' => $uuid]);
    
    if ($model) {
      $model->delete();
      Yii::$app->session->setFlash('success', Yii::t("crelish", 'Page was deleted successfully...'));
    } else {
      Yii::$app->session->setFlash('warning', Yii::t("crelish", 'Page could not be deleted.'));
    }

    // Redirect back to index with the ctype parameter
    $this->redirect(['/crelish/page/index', 'ctype' => $ctype]);
  }
}
