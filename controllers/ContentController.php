<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\CrelishDataResolver;
use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\MatrixBuilderHelper;
use giantbits\crelish\components\CrelishDataManager;
use Yii;
use yii\data\ActiveDataProvider;
use yii\db\Exception;
use yii\filters\AccessControl;
use yii\grid\ActionColumn;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\View;
use giantbits\crelish\components\CrelishArrayHelper;

class ContentController extends CrelishBaseController
{
  public $layout = 'crelish.twig';

  public function behaviors()
  {
    return [
      'access' => [
        'class' => AccessControl::class,
        'only' => ['create', 'index', 'delete', 'update'],
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
  public function init(): void
  {

    parent::init();

    $this->uuid = (!empty(Yii::$app->getRequest()
      ->getQueryParam('uuid'))) ? Yii::$app->getRequest()
      ->getQueryParam('uuid') : null;

    $this->handleSessionAndQueryParams('cr_content_filter');
    $this->handleSessionAndQueryParams('ctype');

    $this->ctype = Yii::$app->session->get('ctype');
  }

  /**
   * [actionIndex description]
   * @return [type] [description]
   * @throws Exception
   */
  public function actionIndex()
  {
    $filter = null;

    // Setup checkbox column for bulk actions
    $checkCol = [
      [
        'class' => 'giantbits\crelish\components\CrelishCheckboxColumn',
      ]
    ];

    // Handle bulk delete actions
    if (!empty($_POST['selection'])) {
      if (\giantbits\crelish\components\CrelishModelResolver::modelExists($this->ctype)) {
        $modelClass = \giantbits\crelish\components\CrelishModelResolver::getModelClass($this->ctype);
        foreach ($_POST['selection'] as $selection) {
          $delModel = $modelClass::findOne($selection);
          $delModel->delete();
        }
      }
    }

    // Handle content filtering
    $searchTerm = $this->handleSessionAndQueryParams('cr_content_filter');

    if (!empty($searchTerm)) {
      $filter = ['freesearch' => $searchTerm];
    }

    if (empty($this->ctype)) {
      return $this->render('content.twig', []);
    }

    // Create a data manager for the content type
    $dataManager = new CrelishDataManager($this->ctype, [
      'filter' => $filter,
      'pageSize' => 25
    ]);

    // Get the element definition
    $elementDefinition = $dataManager->getDefinitions();

    if(empty($elementDefinition)) {
      return $this->render('content.twig', []);
    }

    // Get the data provider
    $dataProvider = null;

    // Get model class for db storage
    $modelClass = null;
    if ($elementDefinition->storage === 'db' && \giantbits\crelish\components\CrelishModelResolver::modelExists($this->ctype)) {
      $modelClass = \giantbits\crelish\components\CrelishModelResolver::getModelClass($this->ctype);
    }

    if ($elementDefinition->storage === 'db' && $modelClass !== null) {
      $query = $modelClass::find();

      // Apply filters
      if ($filter) {
        foreach ($filter as $key => $value) {
          if (is_array($value) && $value[0] === 'strict') {
            $query->andWhere([$key => $value[1]]);
          } elseif ($key === 'freesearch') {
            $searchFragments = explode(" ", trim($value));
            $orConditions = ['or'];

            // Add search conditions for the fields of the main content type
            foreach ($elementDefinition->fields as $field) {
              if (!property_exists($field, 'virtual') || !$field->virtual) {
                // Create an AND condition for each field
                $fieldCondition = ['and'];
                foreach ($searchFragments as $fragment) {
                  $fieldCondition[] = ['like', $this->ctype . '.' . $field->key, $fragment];
                }
                // Add this field's AND condition to the overall OR conditions
                $orConditions[] = $fieldCondition;
              }
            }

            // Add search conditions for related models
            foreach ($elementDefinition->fields as $field) {
              if (property_exists($field, 'type') && $field->type === 'relationSelect' && property_exists($field, 'config')) {
                $config = $field->config;

                if (property_exists($config, 'ctype')) {
                  $relationCtype = $config->ctype;
                  $labelField = property_exists($config, 'labelField') ? $config->labelField : 'systitle';

                  // Make sure the relation is joined (use ctype as it maps to the relation method name)
                  $query->joinWith($config->ctype ?? $field->key);

                  // Get the actual table name from the related model class
                  $tableName = $relationCtype;
                  if (\giantbits\crelish\components\CrelishModelResolver::modelExists($relationCtype)) {
                    $relatedModelClass = \giantbits\crelish\components\CrelishModelResolver::getModelClass($relationCtype);
                    $tableName = $relatedModelClass::tableName();
                  }

                  // Create an AND condition for each related field
                  $relationFieldCondition = ['and'];
                  foreach ($searchFragments as $fragment) {
                    $relationFieldCondition[] = ['like', $tableName . '.' . $labelField, $fragment];
                  }
                  // Add this related field's AND condition to the overall OR conditions
                  $orConditions[] = $relationFieldCondition;
                }
              }
            }

            $query->andWhere($orConditions);
          } else {
            $query->andWhere(['like', $this->ctype . '.' . $key, $value]);
          }
        }
      }

      // Add relations
      $dataManager->setRelations($query);

      if (!empty($elementDefinition->sortDefault)) {
        $sortDefault = (array)$elementDefinition->sortDefault;
        $sortKey = array_key_first($sortDefault);
        $sortDir = $elementDefinition->sortDefault->{$sortKey};

        if (empty($_GET['sort'])) {
          $_GET['sort'] = !(empty($sortKey) && !empty($sortDir))
            ? ($sortDir === 'SORT_ASC' ? $sortKey : "-{$sortKey}")
            : null;
        }
      }

      $dataProvider = new ActiveDataProvider([
        'query' => $query,
        'pagination' => [
          'pageSize' => 25,
          'route' => Yii::$app->request->pathInfo,
          'pageParam' => 'list-page'
        ],
        'sort' => $dataManager->getSorting()
      ]);

    } elseif ($elementDefinition->storage === 'json') {
      $dataProvider = $dataManager->getProvider();
    }

    // Build columns based on visibleInGrid attribute
    $columns = [];

    // Add checkbox column for bulk actions
    $columns = array_merge($columns, $checkCol);

    // Add columns for fields with visibleInGrid = true
    if (isset($elementDefinition->fields)) {
      foreach ($elementDefinition->fields as $field) {
        // Only include fields that have visibleInGrid = true and exclude UUID
        if (property_exists($field, 'visibleInGrid') && $field->visibleInGrid === true && $field->key !== 'uuid') {
          $column = [
            'attribute' => $field->key,
            'label' => property_exists($field, 'label') ? $field->label : null,
            'format' => property_exists($field, 'format') ? $field->format : 'text'
          ];

          // Special handling for state field
          if ($field->key === 'state') {
            $column['format'] = 'raw';
            $column['label'] = Yii::t('i18n', 'Status');
            $column['value'] = function ($data) {
              switch ($data['state']) {
                case 1:
                  return Yii::t('i18n', 'Entwurf');
                case 2:
                  return Yii::t('i18n', 'Online');
                case 3:
                  return Yii::t('i18n', 'Archiviert');
                default:
                  return Yii::t('i18n', 'Offline');
              }
            };
          } // Special handling for dropdown fields
          elseif (property_exists($field, 'items')) {
            $column['format'] = 'raw';
            $column['value'] = function ($data) use ($field) {
              if (!empty($field->items) && !empty($field->items->{$data[$field->key]})) {
                return $field->items->{$data[$field->key]};
              }
              return $data[$field->key];
            };
          } // Special handling for switch inputs
          elseif (property_exists($field, 'type') && str_contains($field->type, 'SwitchInput')) {
            $column['format'] = 'raw';
            $column['value'] = function ($data) use ($field) {
              return $data[$field->key] == 0 ? 'Nein' : 'Ja';
            };
          } // Special handling for value overwrites
          elseif (property_exists($field, 'valueOverwrite')) {
            $column['format'] = 'raw';
            $column['value'] = function ($data) use ($field) {
              return Arrays::get($data, $field->valueOverwrite);
            };
          }
          // Use gridField if specified
          if (property_exists($field, 'gridField') && !empty($field->gridField)) {
            $column['attribute'] = $field->gridField;
          }

          $columns[] = $column;
        }
      }
    }

    $rowOptions = function ($model, $key, $index, $grid) {
      return ['onclick' => 'location.href="update?ctype=' . $this->ctype . '&uuid=' . $model['uuid'] . '";'];
    };

    return $this->render('content.twig', [
      'dataProvider' => $dataProvider,
      'filterProvider' => $dataManager->getFilters(),
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
      'uuid' => $this->uuid
    ]);
  }

  /**
   * [actionUpdate description]
   * @return [type] [description]
   */
  public function actionUpdate()
  {
    $content = $this->buildForm();

    return $this->render('update.twig', [
      'content' => $content,
      'ctype' => $this->ctype,
      'uuid' => $this->uuid
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
    $model = new CrelishDynamicModel(['ctype' => $ctype, 'uuid' => $uuid]);

    if ($model) {
      $model->delete();
      Yii::$app->session->setFlash('success', Yii::t("crelish", 'Content was deleted successfully...'));
    } else {
      Yii::$app->session->setFlash('warning', Yii::t("crelish", 'Content could not be deleted.'));
    }

    // Redirect back to index with the ctype parameter
    $this->redirect(['/crelish/content/index', 'ctype' => $ctype]);
  }

  public function actionApiGet($uuid, $ctype)
  {
    \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

    try {

      $elementDefinition = CrelishDynamicModel::loadElementDefinition($ctype);

      $model = new CrelishDynamicModel([
        'ctype' => $ctype,
        'uuid' => $uuid
      ]);


      if (!$model) {
        return ['error' => 'Content not found'];
      }

      // Format the response using our helper
      return MatrixBuilderHelper::createContentApiResponse($model);
    } catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  public function actionSelector($target = '')
  {
    // Register the content selector overlay mode
    MatrixBuilderHelper::registerContentSelector($this->view, [
      'target' => $target
    ]);

    // Get default content type or from parameter
    $elementType = \Yii::$app->request->get('cet', 'page');

    // Create data provider for this content type
    $modelProvider = CrelishDataResolver::resolveProvider($elementType, []);
    $attributes = ['ctype' => $elementType];
    $filterModel = new CrelishDynamicModel($attributes);

    $elementType = !empty($_GET['cet']) ? $_GET['cet'] : 'page';
    $modelProvider = CrelishDataResolver::resolveProvider($elementType, []);
    $attributes = ['ctype' => $elementType];
    $filterModel = new CrelishDynamicModel($attributes);

    return $this->render('selector.twig', [
      'dataProvider' => method_exists($modelProvider, 'getProvider') ? $modelProvider->getProvider() : $modelProvider,
      'filterModel ' => $filterModel,
      'columns' => [
        'systitle',
        [
          'class' => ActionColumn::class,
          'template' => '{update}',
          'buttons' => [
            'update' => function ($url, $model, $elementType) {
              if (!is_array($model)) {
                $ctype = explode('\\', strtolower($model::class));
                $ctype = end($ctype);
              } else {
                $ctype = $elementType;
              }

              return Html::a('<span class="fa-sharp  fa-regular fa-plus"></span>', '', [
                'title' => \Yii::t('app', 'Add'),
                'data-pjax' => '0',
                'data-content' => Json::encode(
                  [
                    'uuid' => is_array($model) ? $model['uuid'] : $model->uuid,
                    'ctype' => is_array($model) ? $model['ctype'] : $model->ctype,
                    'info' => [
                      [
                        'label' => \Yii::t('app', 'Titel intern'),
                        'value' => is_array($model) ? $model['systitle'] : $model->systitle
                      ],
                      [
                        'label' => \Yii::t('app', 'Status'),
                        'value' => is_array($model) ? $model['state'] : $model->state
                      ]
                    ]
                  ]),
                'class' => 'cntAdd'
              ]);
            }
          ]
        ]
      ],
      'currentType' => $elementType,
      'target' => $target
    ]);
  }

  /**
   * Override the setupHeaderBar method for content-specific components
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
        // For content index, add search and create buttons
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

      case 'selector':
        // For update actions, add back button and save buttons (with delete)
        $this->view->params['headerBarLeft'][] = 'search';
        break;

      default:
        // For other actions, just keep the defaults
        break;
    }
  }
}
