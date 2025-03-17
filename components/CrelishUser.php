<?php
	
	namespace giantbits\crelish\components;
	
	use app\workspace\models\Company;
	use app\workspace\models\User;
	use yii\base\BaseObject;
	use yii\base\NotSupportedException;
	use yii\data\ActiveDataProvider;
	
	class CrelishUser extends BaseObject implements \yii\web\IdentityInterface
	{
		
		private $customProperties = [];
		
		public $loginUrl = ['crelish/user/login'];
		/**
		 * [$id description].
		 *
		 * @var [type]
		 */
		public $id;
		
		public $code;
		public $codeSend;
		public $reminderSend;
		public $lang;
		public $activationDate;
		public $trialEndAt;
		
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
		public $phone;
		public $address;
		public $zip;
		public $city;
		public $country;
		public $user;
		public $role;
		public $password;
		public $state;
		public $from;
		public $created;
		public $updated;
		public $to;
		public $initials;
		public $stripeId;
		public $cardBrand;
		public $cardLastFour;
		
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
				if (!empty($user) && $user->state == 2) {
					$user->initials = substr($user->nameFirst, 0, 1) . substr($user->nameLast, 0, 1);
					return \Yii::$app->user->login(new static($user), 3600);
				}
			} else {
				$user = User::findOne(['email' => $data['email']]);
				if (!empty($user) && $user->state == 2) {
					if (\Yii::$app->getSecurity()->validatePassword($data['password'], $user['password'])) {
						$user->initials = substr($user->nameFirst, 0, 1) . substr($user->nameLast, 0, 1);
						return \Yii::$app->user->login(new static($user), 3600);
					}
				}
			}
			
			return false;
		}
		
		public function getInitials()
		{
			return substr($this->nameFirst, 0, 1) . substr($this->nameLast, 0, 1);;
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
		 * [findIdentity description].
		 *
		 * @param [type] $id [description]
		 *
		 * @return [type] [description]
		 */
		public static function findIdentity($id)
		{
			$user = User::findOne(['uuid' => $id]);
			$userData = new static($user);
			
			if (class_exists('Company')) {
				$company = Company::find()->where(['=', 'uuid', $userData->company])->one();
				if ($company) {
					$userData->companyName = $company->systitle;
				}
			}
			
			return $userData;
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
		 * Find a user by username
		 * 
		 * @param string $username The username to search for
		 * @return static|null The user identity instance or null if not found
		 */
		public static function findByUsername(string $username)
		{
			// First try to find by username
			$user = User::findOne(['username' => $username]);
			
			// If not found, try by email (which is often used as username)
			if (!$user) {
				$user = User::findOne(['email' => $username]);
			}
			
			if ($user) {
				return new static($user);
			}
			
			return null;
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
			return \Yii::$app->security->validatePassword($password, $this->password);
		}
		
		public function __get($name)
		{
			if (array_key_exists($name, $this->customProperties)) {
				return $this->customProperties[$name];
			}
			
			return parent::__get($name);
		}
		
		public function __set($name, $value)
		{
			$this->customProperties[$name] = $value;
		}
		
		public function __isset($name)
		{
			return isset($this->customProperties[$name]) || parent::__isset($name);
		}
		
		public function __unset($name)
		{
			if (isset($this->customProperties[$name])) {
				unset($this->customProperties[$name]);
			} else {
				parent::__unset($name);
			}
		}
	}
