<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use Yii;
use yii\filters\AccessControl;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use yii\web\Response;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

class ElementsController extends CrelishBaseController
{
  public $layout = 'crelish.twig';

  public function behaviors()
  {
    return [
      'access' => [
        'class' => AccessControl::class,
        'rules' => [
          [
            'allow' => true,
            'actions' => ['login'],
            'roles' => ['?'],
          ],
          [
            'allow' => true,
            'actions' => [],
            'roles' => ['@'],
          ],
        ],
      ],
    ];
  }

  public function init()
  {
    parent::init();
  }

  /**
   * Lists all element types
   * 
   * @return string
   */
  public function actionIndex() {
    $files = FileHelper::findFiles(Yii::getAlias('@app/workspace/elements'));
    $data = [];
    foreach ($files as $f) {
      $tmp = json_decode(file_get_contents($f));
      $data[$tmp->label] = basename($f);
    }
    ksort($data);

    return $this->render('index.twig', ['data' => $data]);
  }

  /**
   * Edit an element type
   * 
   * @return string
   * @throws NotFoundHttpException
   */
  public function actionEdit() {
    if (!isset($_GET['element']) || !preg_match('/^[a-zA-Z0-9\-_]+\.json$/', $_GET['element'])) {
      throw new BadRequestHttpException('Invalid element name');
    }
    
    $elementFileName = $_GET['element'];
    $elementPath = Yii::getAlias('@app/workspace/elements/' . $elementFileName);
    
    if (!file_exists($elementPath)) {
      throw new NotFoundHttpException('Element not found');
    }
    
    $data = json_decode(file_get_contents($elementPath));

    // Prepare field data for the editor
    foreach ($data->fields as $key => $val) {
      $data->fields[$key]->fielddata = json_encode($val);
    }

    return $this->render('edit.twig', ['element' => $data]);
  }
  
  /**
   * Create a new element type
   * 
   * @return string
   */
  public function actionCreate() {
    return $this->render('create.twig');
  }
  
  /**
   * Save an element type (new or existing)
   * 
   * @return Response
   */
  public function actionSave() {
    Yii::$app->response->format = Response::FORMAT_JSON;
    
    if (!Yii::$app->request->isPost) {
      return [
        'success' => false,
        'message' => 'Invalid request method'
      ];
    }
    
    $post = Yii::$app->request->post();
    
    if (empty($post['key'])) {
      return [
        'success' => false,
        'message' => 'Element key is required'
      ];
    }
    
    $key = $post['key'];
    $isNew = false; //empty($post['uuid']);
    
    // Validate key format
    if (!preg_match('/^[a-z0-9_]+$/', $key)) {
      return [
        'success' => false,
        'message' => 'Element key must contain only lowercase letters, numbers, and underscores'
      ];
    }
    
    $elementPath = Yii::getAlias('@app/workspace/elements/' . $key . '.json');
    
    // Check if element already exists for new elements
    if ($isNew && file_exists($elementPath)) {
      return [
        'success' => false,
        'message' => 'An element with this key already exists'
      ];
    }
    
    // Prepare element data
    $element = [
      'key' => $key,
      'label' => $post['label'] ?? $key,
      'storage' => $post['storage'] ?? 'db',
      'category' => $post['category'] ?? 'Content',
      'selectable' => $post['selectable'] ?? true,
      'tabs' => $post['tabs'] ?? [],
      'fields' => $post['fields'] ?? [],
      'sortDefault' => $post['sortDefault'] ?? ['systitle' => 'SORT_ASC']
    ];
    
    // Ensure fields is an array
    if (is_string($element['fields'])) {
      $element['fields'] = json_decode($element['fields'], true);
    }
    
    // Ensure tabs is an array
    if (is_string($element['tabs'])) {
      $element['tabs'] = json_decode($element['tabs'], true);
    }
    
    try {
      // Save the element definition
      file_put_contents($elementPath, Json::encode($element, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      
      return [
        'success' => true,
        'message' => $isNew ? 'Element created successfully' : 'Element updated successfully',
        'key' => $key
      ];
    } catch (\Exception $e) {
      return [
        'success' => false,
        'message' => 'Error saving element: ' . $e->getMessage()
      ];
    }
  }
  
  /**
   * Delete an element type
   * 
   * @return Response
   */
  public function actionDelete() {
    Yii::$app->response->format = Response::FORMAT_JSON;
    
    if (!Yii::$app->request->isPost) {
      return [
        'success' => false,
        'message' => 'Invalid request method'
      ];
    }
    
    $key = Yii::$app->request->post('key');
    
    if (empty($key)) {
      return [
        'success' => false,
        'message' => 'Element key is required'
      ];
    }
    
    $elementPath = Yii::getAlias('@app/workspace/elements/' . $key . '.json');
    
    if (!file_exists($elementPath)) {
      return [
        'success' => false,
        'message' => 'Element not found'
      ];
    }
    
    try {
      unlink($elementPath);
      
      return [
        'success' => true,
        'message' => 'Element deleted successfully'
      ];
    } catch (\Exception $e) {
      return [
        'success' => false,
        'message' => 'Error deleting element: ' . $e->getMessage()
      ];
    }
  }
  
  /**
   * Get element type details
   * 
   * @return Response
   */
  public function actionGet() {
    Yii::$app->response->format = Response::FORMAT_JSON;
    
    $key = Yii::$app->request->get('key');
    
    if (empty($key)) {
      return [
        'success' => false,
        'message' => 'Element key is required'
      ];
    }
    
    $elementPath = Yii::getAlias('@app/workspace/elements/' . $key . '.json');
    
    if (!file_exists($elementPath)) {
      return [
        'success' => false,
        'message' => 'Element not found'
      ];
    }
    
    try {
      $element = json_decode(file_get_contents($elementPath), true);
      
      return [
        'success' => true,
        'data' => $element
      ];
    } catch (\Exception $e) {
      return [
        'success' => false,
        'message' => 'Error loading element: ' . $e->getMessage()
      ];
    }
  }
  
  /**
   * List all available field types
   * 
   * @return Response
   */
  public function actionFieldTypes() {
    Yii::$app->response->format = Response::FORMAT_JSON;
    
    $fieldTypes = [
      'textInput' => 'Text Input',
      'textArea' => 'Text Area',
      'numberInput' => 'Number Input',
      'dropDownList' => 'Dropdown List',
      'checkboxList' => 'Checkbox List',
      'assetConnector' => 'Asset Connector',
      'relationSelect' => 'Relation Select',
      'jsonEditor' => 'JSON Editor',
      'jsonEditorNew' => 'JSON Editor (Schema Form)',
      'widget_\\brussens\\yii2\\extensions\\trumbowyg\\TrumbowygWidget' => 'Rich Text Editor'
    ];
    
    return [
      'success' => true,
      'data' => $fieldTypes
    ];
  }

  /**
   * Override the setupHeaderBar method to use elements-specific components
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
        // For elements index, show title and add button
        $this->view->params['headerBarLeft'][] = 'elements-title';
        $this->view->params['headerBarRight'][] = 'create-element-button';
        break;
        
      case 'edit':
      case 'create':
        // For edit/create actions, add back button and save buttons
        $this->view->params['headerBarLeft'][] = 'back-button';
        $this->view->params['headerBarRight'] = ['save'];
        break;
        
      default:
        // For other actions, just keep the defaults
        break;
    }
  }
}
