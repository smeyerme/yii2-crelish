<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\ContentUrlResolver;
use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\menus\MenuAdminTree;
use giantbits\crelish\components\menus\MenuTreeSaver;
use giantbits\crelish\models\Menu;
use giantbits\crelish\models\MenuItem;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\Json;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Admin for navigation menus: list, metadata, tree editor and its JSON API.
 */
class MenuController extends CrelishBaseController
{
  public $layout = 'crelish.twig';

  public function behaviors(): array
  {
    return [
      'access' => [
        'class' => AccessControl::class,
        'rules' => [['allow' => true, 'roles' => ['@']]],
      ],
      'verbs' => [
        'class' => VerbFilter::class,
        'actions' => ['delete' => ['POST'], 'save' => ['POST']],
      ],
    ];
  }

  protected function setupHeaderBar()
  {
    parent::setupHeaderBar();

    // The base controller adds content-form save/delete buttons for create/update;
    // the menu views bring their own buttons
    $this->view->params['headerBarRight'] = $this->action && $this->action->id === 'index' ? ['create'] : [];
  }

  public function actionIndex(): string
  {
    $this->view->title = Yii::t('crelish', 'Navigation');
    $resolver = new ContentUrlResolver();
    $rows = [];

    foreach (Menu::find()->orderBy(['systitle' => SORT_ASC])->all() as $menu) {
      $items = MenuItem::find()->where(['menu_uuid' => $menu->uuid])->all();
      $unavailable = 0;

      foreach ($items as $item) {
        if ($item->target_type === MenuItem::TARGET_CONTENT
          && $resolver->resolveContent((string)$item->target_ctype, (string)$item->target_uuid) === null) {
          $unavailable++;
        }
      }

      $rows[] = ['menu' => $menu, 'count' => count($items), 'unavailable' => $unavailable];
    }

    return $this->render('index.twig', ['rows' => $rows]);
  }

  public function actionCreate()
  {
    return $this->form(new Menu(['max_depth' => 2, 'state' => Menu::STATE_ONLINE]));
  }

  public function actionUpdate(string $uuid)
  {
    return $this->form($this->findMenu($uuid));
  }

  public function actionDelete(string $uuid): Response
  {
    if ($this->findMenu($uuid)->delete()) {
      Yii::$app->session->setFlash('success', Yii::t('crelish', 'Menu deleted.'));
    } else {
      Yii::$app->session->setFlash('warning', Yii::t('crelish', 'Menu could not be deleted.'));
    }

    return $this->redirect(['index']);
  }

  public function actionEdit(string $uuid): string
  {
    $menu = $this->findMenu($uuid);
    $this->view->title = $menu->systitle;
    $this->registerEditorBundle();

    return $this->render('edit.twig', [
      'menu' => $menu,
      'csrfToken' => Yii::$app->request->csrfToken,
    ]);
  }

  public function actionTree(string $uuid): array
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    return MenuAdminTree::build($this->findMenu($uuid));
  }

  public function actionSave(string $uuid): array
  {
    Yii::$app->response->format = Response::FORMAT_JSON;
    $menu = $this->findMenu($uuid);

    try {
      $payload = Json::decode(Yii::$app->request->getRawBody());
    } catch (\Throwable) {
      $payload = null;
    }

    if (!is_array($payload)) {
      Yii::$app->response->statusCode = 400;

      return ['error' => Yii::t('crelish', 'Invalid request.')];
    }

    $result = (new MenuTreeSaver($menu))->save($payload);
    Yii::$app->response->statusCode = $result['status'];

    return $result['body'];
  }

  private function form(Menu $menu)
  {
    $request = Yii::$app->request;

    if ($request->isPost) {
      $data = $request->post('Menu', []);

      if (!$menu->isNewRecord) {
        unset($data['key']);
      }

      $menu->setAttributes($data);

      if ($menu->save()) {
        Yii::$app->session->setFlash('success', Yii::t('crelish', 'Menu saved.'));

        return $this->redirect(['edit', 'uuid' => $menu->uuid]);
      }

      Yii::$app->session->setFlash('error', Yii::t('crelish', 'Please correct the highlighted fields.'));
    }

    $this->view->title = $menu->isNewRecord ? Yii::t('crelish', 'New menu') : $menu->systitle;

    return $this->render('form.twig', [
      'menu' => $menu,
      'csrfParam' => $request->csrfParam,
      'csrfToken' => $request->csrfToken,
    ]);
  }

  private function findMenu(string $uuid): Menu
  {
    $menu = Menu::findOne($uuid);

    if ($menu === null) {
      throw new NotFoundHttpException(Yii::t('crelish', 'Menu not found.'));
    }

    return $menu;
  }

  private function registerEditorBundle(): void
  {
    $source = Yii::getAlias('@giantbits/crelish/resources/menu-editor/dist/menu-editor.js');
    $published = Yii::$app->assetManager->publish($source, ['forceCopy' => YII_DEBUG, 'appendTimestamp' => true])[1];
    $this->view->registerJsFile($published);
  }
}
