<?php

namespace giantbits\crelish\modules\api\components;

/**
 * Makes an identity found by an auth method the request's user.
 *
 * Several lookups (JWT "sub", access_token inside a JWT) only returned the
 * identity, so Yii::$app->user stayed a guest and AccessControl denied the
 * request. setIdentity() applies it to this request only (no session login).
 */
trait AuthenticatesIdentity
{
  public function authenticate($user, $request, $response)
  {
    $identity = $this->findIdentity($user, $request, $response);

    if ($identity !== null && $user->getIdentity(false) !== $identity) {
      $user->setIdentity($identity);
    }

    return $identity;
  }
}
