<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\ContentTargetSearch;
use giantbits\crelish\components\CrelishAccess;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;

/**
 * JSON endpoints for link target pickers.
 */
class ContentTargetController extends Controller
{
  public function behaviors(): array
  {
    return [
      'access' => [
        'class' => AccessControl::class,
        // Admin only (login + admin role), like every CrelishBaseController
        'rules' => [CrelishAccess::adminRule()],
      ],
    ];
  }

  public function beforeAction($action): bool
  {
    \Yii::$app->response->format = Response::FORMAT_JSON;

    return parent::beforeAction($action);
  }

  public function actionTypes(): array
  {
    return ContentTargetSearch::types();
  }

  public function actionSearch(string $ctype, string $q = ''): array
  {
    return ContentTargetSearch::search($ctype, $q);
  }
}
