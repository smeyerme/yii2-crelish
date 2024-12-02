<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishDataProvider;
use giantbits\crelish\components\CrelishDynamicModel;
use giantbits\crelish\components\CrelishBaseController;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\View;
use function _\find;
use function _\internal\parent;
use function _\map;

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

    Yii::$app->view->registerJs('
      $(document).on("pjax:complete" , function(event) {
        $(".scrollable").animate({ scrollTop: "0" });
        
		    if($(".filter-top").length > 0) {
		      var psT = new PerfectScrollbar(".filter-top", {
		        suppressScrollY: true
		      });
		    }
      });
    ', View::POS_LOAD);

  }

  /**
   * [actionIndex description]
   * @return [type] [description]
   */
  public function actionIndex()
  {
    $filter = null;
    $checkCol = [
      [
        'class' => 'giantbits\crelish\components\CrelishCheckboxColumn',
      ]
    ];

    $modelClass = '\app\workspace\models\\' . ucfirst($this->ctype);
    if (!empty($_POST['selection'])) {
      if (class_exists($modelClass)) {
        foreach ($_POST['selection'] as $selection) {
          $delModel = $modelClass::findOne($selection);
          $delModel->delete();
        }
      }
    }

    if (key_exists('cr_content_filter', $_GET)) {
      $filter = ['freesearch' => $_GET['cr_content_filter']];
    } else {
      if (!empty(Yii::$app->session->get('cr_content_filter'))) {
        $filter = ['freesearch' => Yii::$app->session->get('cr_content_filter')];
      }
    }

    $modelInfo = new CrelishDataProvider($this->ctype, ['filter' => $filter], null, null, true);
    $modelProvider = null;

    if ($modelInfo->definitions->storage === 'db' && class_exists($modelClass)) {
      $query = $modelInfo->getQuery($modelClass::find(), $filter);

      // Add relations.
      $modelInfo->setRelations($query);

      if (!empty($modelInfo->definitions->sortDefault)) {
        $sortKey = key($modelInfo->definitions->sortDefault);
        $sortDir = $modelInfo->definitions->sortDefault->{$sortKey};
      }

      $modelProvider = new ActiveDataProvider([
        'query' => $query,
        'pagination' => [
          'pageSize' => 25,
        ],
        'sort' => [
          'defaultOrder' => !empty($sortKey) && !empty($sortDir) ? [$sortKey => $sortDir] : null
        ],
      ]);
    } elseif ($modelInfo->definitions->storage === 'json') {
      $modelProvider = $modelInfo->getArrayProvider();
    }

    $columns = array_merge($checkCol, $modelInfo->columns);
    $columns = map($columns, function ($item) use ($modelInfo) {

      if (key_exists('attribute', $item) && $item['attribute'] === 'state') {
        $item['format'] = 'raw';
        $item['label'] = 'Status';
        $item['value'] = function ($data) {
          switch ($data['state']) {
            case 1:
              $state = Yii::t('i18n', 'Entwurf');
              break;
            case 2:
              $state = Yii::t('i18n', 'Online');
              break;
            case 3:
              $state = Yii::t('i18n', 'Archiviert');
              break;
            default:
              $state = Yii::t('i18n', 'Offline');
          };

          return $state;
        };
      }
      if (key_exists('attribute', $item)) {
        // Add magic here: get definition for attribute, check for items, use items for label display.
        $itemDef = find($modelInfo->definitions->fields, function ($itm) use ($item) {
          return $itm->key == $item['attribute'];
        });

        if (is_object($itemDef) && property_exists($itemDef, 'items')) {
          $item['format'] = 'raw';
          $item['label'] = $itemDef->label;
          $item['value'] = function ($data) use ($itemDef) {
            if (!empty($itemDef->items) && !empty($itemDef->items->{$data[$itemDef->key]})) {
              return $itemDef->items->{$data[$itemDef->key]};
            }
          };
        } elseif (is_object($itemDef) && property_exists($itemDef, 'type') && str_contains($itemDef->type, 'SwitchInput')) {
          $item['format'] = 'raw';
          $item['label'] = $itemDef->label;
          $item['value'] = function ($data) use ($itemDef) {
            return $data[$itemDef->key] == 0 ? 'Nein' : 'Ja';
          };
        } elseif (is_object($itemDef) && property_exists($itemDef, 'valueOverwrite')) {
          $item['format'] = 'raw';
          $item['value'] = function ($data) use ($itemDef) {
            return Arrays::get($data, $itemDef->valueOverwrite);
          };
        }
      }

      return $item;
    });

    $rowOptions = function ($model, $key, $index, $grid) {
      return ['onclick' => 'location.href="update?ctype=' . $this->ctype . '&uuid=' . $model['uuid'] . '";'];
    };

    return $this->render('content.twig', [
      'dataProvider' => $modelProvider,
      'filterProvider' => $modelInfo->getFilters(),
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
    $ctype = $_GET['ctype'];
    $uuid = $_GET['uuid'];
    $model = new CrelishDynamicModel([], ['ctype' => $ctype, 'uuid' => $uuid]);

    if ($model) {
      $model->delete();
      Yii::$app->session->setFlash('success', Yii::t("crelish", 'Content was deleted successfully...'));
    } else {
      Yii::$app->session->setFlash('warning', Yii::t("crelish", 'Content could not be deleted.'));
    }

    $this->redirect('/crelish/content/index');
  }

  private function handleSessionAndQueryParams($paramName)
  {
    if (isset($_GET[$paramName])) {
      Yii::$app->session->set($paramName, $_GET[$paramName]);
    } elseif (Yii::$app->session->get($paramName) !== null) {
      Yii::$app->request->setQueryParams([$paramName => Yii::$app->session->get($paramName)]);
    }
  }
}
