<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\Analytics\BrowserConfirmation;
use giantbits\crelish\components\CrelishAnalyticsComponent;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Receives the browser's report that a page was shown (BrowserConfirmation).
 *
 * Deliberately a plain controller: CrelishBaseController redirects anyone who
 * is not an admin. The answer is always empty, whatever was sent, so the
 * endpoint tells a caller nothing about page views or sessions.
 */
class PageStateController extends Controller
{
  // The page view must belong to the caller's own session; that is the check.
  public $enableCsrfValidation = false;

  public function beforeAction($action)
  {
    if (!BrowserConfirmation::isEnabled()) {
      throw new NotFoundHttpException();
    }

    return parent::beforeAction($action);
  }

  public function actionIndex(): Response
  {
    $request = Yii::$app->request;
    $response = Yii::$app->response;
    $response->statusCode = 204;
    $response->headers->set('Cache-Control', 'no-store');

    $session = Yii::$app->session;
    // A caller without a session has no page view to confirm; do not start one for it
    if (!$request->getIsPost() || !$session->getHasSessionId()) {
      return $response;
    }

    $sessionId = $session->get(CrelishAnalyticsComponent::SESSION_KEY);
    $pageViewId = $request->post('v');

    if (is_string($sessionId) && is_string($pageViewId) && ctype_digit($pageViewId) && strlen($pageViewId) <= 10) {
      BrowserConfirmation::confirm(Yii::$app->db, (int)$pageViewId, $sessionId);
    }

    return $response;
  }
}
