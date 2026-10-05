<?php

namespace giantbits\crelish\components;

use Yii;
use yii\base\Action;
use yii\web\Response;
use yii\web\User;

/**
 * Who may use the Crelish admin.
 *
 * The admin requires a logged-in user with role >= ADMIN_ROLE. Every
 * controller extending CrelishBaseController is guarded in its beforeAction()
 * (see guard()); admin controllers that extend another base class add
 * adminRule() to their AccessControl. Only the routes in PUBLIC_ROUTES are
 * reachable without that role.
 */
final class CrelishAccess
{
  public const ADMIN_ROLE = 9;

  /**
   * Admin-module routes (controller id / action id) that are public by design, with the reason.
   * Their own controller rules still apply (e.g. AccessControl in AssetController, TrackController).
   */
  public const PUBLIC_ROUTES = [
    'user/login' => 'the login form itself',
    'user/logout' => 'ends the session; must also work for logged-in users without the admin role',
    'asset/glide' => 'resized images for the public frontend (img src/srcset)',
    'asset/download' => 'asset downloads linked from the public frontend',
    'track/click' => 'click tracking beacon of the public frontend (signed tokens, rate limited)',
  ];

  public static function isPublicRoute(string $route): bool
  {
    return array_key_exists($route, self::PUBLIC_ROUTES);
  }

  /**
   * Logged in with the admin role.
   */
  public static function isAdmin(?User $user = null): bool
  {
    $user ??= Yii::$app->has('user') ? Yii::$app->getUser() : null;

    if ($user === null || $user->getIsGuest()) {
      return false;
    }

    $role = $user->getIdentity()->role ?? null;

    return is_numeric($role) && (int)$role >= self::ADMIN_ROLE;
  }

  /**
   * AccessControl rule for admin controllers that do not extend CrelishBaseController.
   */
  public static function adminRule(array $rule = []): array
  {
    return array_merge([
      'allow' => true,
      'roles' => ['@'],
      'matchCallback' => static fn() => self::isAdmin(),
    ], $rule);
  }

  /**
   * Admin guard for CrelishBaseController::beforeAction().
   *
   * Public routes and admins pass. Otherwise the action is stopped (false):
   * AJAX/JSON requests get a bare 403, guests are sent to the login form,
   * logged-in users without the admin role to the home page.
   */
  public static function guard(Action $action): bool
  {
    $route = $action->controller->id . '/' . $action->id;

    if (self::isPublicRoute($route) || self::isAdmin()) {
      return true;
    }

    $request = Yii::$app->getRequest();
    $response = Yii::$app->getResponse();
    $user = Yii::$app->getUser();

    if (self::wantsJson($request)) {
      $response->format = Response::FORMAT_JSON;
      $response->data = ['success' => false, 'error' => 'Forbidden'];
      $response->setStatusCode(403);
      return false;
    }

    if ($user->getIsGuest()) {
      if ($user->enableSession && $request->getIsGet()) {
        $user->setReturnUrl($request->getUrl());
      }

      $response->redirect($user->loginUrl ?? ['/crelish/user/login']);
      return false;
    }

    $response->redirect(['/']);
    return false;
  }

  private static function wantsJson(\yii\base\Request $request): bool
  {
    if (!$request instanceof \yii\web\Request) {
      return false;
    }

    return $request->getIsAjax() || str_contains(strtolower((string)$request->getHeaders()->get('Accept', '')), 'application/json');
  }
}
