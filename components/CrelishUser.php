<?php

namespace giantbits\crelish\components;

use yii\web\User;
use yii\base\NotSupportedException;

class CrelishUser extends \yii\web\User implements \yii\web\IdentityInterface
{
    public $loginUrl = ['crelish/user/login'];

    /**
     * [$id description].
     *
     * @var [type]
     */
    public $id;

    /**
     * [$authKey description].
     *
     * @var [type]
     */
    public $authKey;

    /**
     * [$accessToken description].
     *
     * @var [type]
     */
    public $accessToken;

    /**
     * [$uuid description].
     *
     * @var [type]
     */
    public $uuid;

    /**
     * [$email description].
     *
     * @var [type]
     */
    public $email;

    /**
     * [$username description].
     *
     * @var [type]
     */
    public $username;

    /**
     * [$identityClass description].
     *
     * @var string
     */
    public $identityClass = 'CrelishUser';

    /**
     * [$rememberMe description].
     *
     * @var bool
     */
    public $rememberMe = false;

    /**
     * [$ctype description]
     * @var string
     */
    public $ctype = 'user';

    public $salutation;
    public $nameLast;
    public $nameFirst;
    public $company;
    public $user;

    /**
     * [crelishLogin description].
     *
     * @param [type] $data [description]
     *
     * @return [type] [description]
     */
    public static function crelishLogin($data) {
        // Fetch the single wanted user only.
        $userProvider = new CrelishJsonDataProvider('user', ['filter'=>['email' => $data['email']]]);
        $user = $userProvider->one();

        if(!empty($user)) {
          if(\Yii::$app->getSecurity()->validatePassword($data['password'], $user['password'])) {
            self::prepareUserdata($user);
            return \Yii::$app->user->login(new static($user), 0);
          }
        }

        return false;
    }

    /**
     * [getId description].
     *
     * @return [type] [description]
     */
    public function getId() {
        return $this->uuid;
    }

    /**
     * [prepareUserdata description].
     *
     * @param [type] $userData [description]
     * @param [type] $file     [description]
     *
     * @return [type] [description]
     */
    private static function prepareUserdata(&$userData) {
        unset($userData['password']);
        unset($userData['login']);
        unset($userData['path']);
        unset($userData['slug']);
        unset($userData['state']);
        unset($userData['created']);
        unset($userData['updated']);
        unset($userData['from']);
        unset($userData['to']);
        //unset($userData['salutation']);
        //unset($userData['nameFirst']);
        //unset($userData['nameLast']);
        $userData['username'] = $userData['email'];
    }

    /**
     * [findIdentity description].
     *
     * @param [type] $id [description]
     *
     * @return [type] [description]
     */
    public static function findIdentity($id)
    {

      $userProvider = new CrelishJsonDataProvider('user', null, $id);
      $userData = $userProvider->one();
      self::prepareUserdata($userData);

      return new static($userData);
    }

    /**
     * [findIdentityByAccessToken description].
     *
     * @param [type] $token [description]
     * @param [type] $type  [description]
     *
     * @return [type] [description]
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        throw new NotSupportedException('"findIdentityByAccessToken" is not implemented.');
    }

    /**
     * [getAuthKey description].
     *
     * @return [type] [description]
     */
    public function getAuthKey()
    {
        return $this->authKey;
    }

    /**
     * [validateAuthKey description].
     *
     * @param [type] $authKey [description]
     *
     * @return [type] [description]
     */
    public function validateAuthKey($authKey)
    {
        return $this->getAuthKey() === $authKey;
    }

    /**
     * Validates password.
     *
     * @param string $password password to validate
     *
     * @return bool if password provided is valid for current user
     */
    public function validatePassword($password)
    {
      return true; //$this->getPassword() === $password;
    }
}
