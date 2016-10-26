<?php
namespace giantbits\crelish\components;
use yii\web\User;


class CrelishUser extends \yii\web\User implements \yii\web\IdentityInterface {
    public $loginUrl = ['crelish/user/login'];

    public $id;
    public $authKey;
    public $accessToken;

    public $uuid;
    public $email;
    public $username;
    public $identityClass = 'CrelishUser';
    public $rememberMe = false;

    public static function crelishLogin($data) {
    	//get all users

		$userPath = realpath(\Yii::getAlias('@app/workspace/data/user'));
		transformer\CrelishFieldTransformerMd5::transform($data['password']);
		if ($userPath !== false) {
	    	foreach (glob($userPath . "/*-*-*-*-*.json") as $file) {
	    		$userData = \yii\helpers\Json::decode(file_get_contents($file));
	    		if ($userData['email'] == $data['email'] && $userData['password'] == $data['password']) {
	    			CrelishUser::prepareUserdata($userData,$file);
	    			return \Yii::$app->user->login(new static($userData), 0);
	    		}
			}
		}
		return false;
    }

    public function getId() {
    	return $this->id;
    }

    private static function prepareUserdata(&$userData,$file) {
		unset($userData['password']);
		unset($userData['login']);
		unset($userData['path']);
		unset($userData['slug']);
		unset($userData['state']);
		unset($userData['created']);
		unset($userData['updated']);
		unset($userData['from']);
		unset($userData['to']);
		$userData['id'] = substr($file,-41,36);
		$userData['username'] = $userData['email'];
    }

    /**
     * @inheritdoc
     */
    public static function findIdentity($id)
    {
		$file = realpath(\Yii::getAlias('@app/workspace/data/user')) . '/' . $id . '.json';
    	if (file_exists($file)) {
    		$userData = \yii\helpers\Json::decode(file_get_contents($file));
    		CrelishUser::prepareUserdata($userData,$file);
    		return new static($userData);
    	}
        return null;
    }

    /**
     * @inheritdoc
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
    	echo "1";
    	die();
/*
        foreach (self::$users as $user) {
            if ($user['accessToken'] === $token) {
                return new static($user);
            }
        }

*/
        return null;
    }

    /**
     * @inheritdoc
     */
    public function getAuthKey()
    {
        return $this->authKey;
    }

    /**
     * @inheritdoc
     */
    public function validateAuthKey($authKey)
    {
    	echo "4";
    	die();
//        return $this->authKey === $authKey;
        return null;
    }

    /**
     * Validates password
     *
     * @param string $password password to validate
     * @return bool if password provided is valid for current user
     */
    public function validatePassword($password)
    {
    	echo "5";
    	die();
        return $this->password === $password;
    }

}
