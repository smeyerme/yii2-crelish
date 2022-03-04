<?php
  
  namespace giantbits\crelish\components;
  
  use app\workspace\models\User;
  use yii\base\BaseObject;
  use yii\base\NotSupportedException;
  
  class CrelishUser extends BaseObject implements \yii\web\IdentityInterface
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
    public $role;
    public $password;
    public $state;
    public $from;
    public $created;
    public $updated;
    public $to;
    public $stripeId;
    public $cardBrand;
    public $cardLastFour;
    public $trialEndAt;
    public $activationDate;
    
    /**'
     * [crelishLogin description].
     *
     * @param [type] $data [description]
     *
     * @return [type] [description]
     */
    public static function crelishLogin($data)
    {
      
      // Fetch the single wanted user only.
      if (!empty($data['uuid'])) {
        $user = User::findOne(['uuid' => $data['uuid']]);
        if (!empty($user)) {
          self::prepareUserdata($user);
          return \Yii::$app->user->login(new static($user), 3600);
        }
      }  else {
        $user = User::findOne(['email' => $data['email']]);
      if (!empty($user)) {
        if (\Yii::$app->getSecurity()->validatePassword($data['password'], $user['password'])) {
          self::prepareUserdata($user);
            return \Yii::$app->user->login(new static($user), 3600);
          }
        }
      }
      
      return false;
    }
    
    /**
     * [getId description].
     *
     * @return [type] [description]
     */
    public function getId()
    {
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
    private static function prepareUserdata(&$userData)
    {
      unset($userData['password']);
      unset($userData['login']);
      unset($userData['path']);
      unset($userData['slug']);
      unset($userData['state']);
      unset($userData['created']);
      unset($userData['updated']);
      unset($userData['from']);
      unset($userData['to']);
      $userData['username'] = !empty($userData['nameFirst']) ? $userData['nameFirst'] . ' ' . (!empty($userData['nameLast']) ? $userData['nameLast'] : '') : 'You';
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
      $userProvider = new CrelishDataProvider('user', null, $id);
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
