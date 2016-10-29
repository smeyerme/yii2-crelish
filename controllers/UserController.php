<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishUser;
use giantbits\crelish\components\CrelishJsonDataProvider;
use giantbits\crelish\components\CrelishDynamicJsonModel;
use yii\helpers\Url;

class UserController extends CrelishBaseController
{
    /**
   * [$layout description].
   *
   * @var string
   */
  public $layout = 'simple.twig';

  /**
   * [init description].
   *
   * @return [type] [description]
   */
  public function init()
  {
      parent::init();
      $this->ctype = 'user';
      $this->uuid = (!empty(\Yii::$app->getRequest()->getQueryParam('uuid'))) ? \Yii::$app->getRequest()->getQueryParam('uuid') : null;

      // create default user element, if none is present
      $workspacePath = realpath(\Yii::getAlias('@webroot').'/../workspace');
      if ($workspacePath === false) {
          throw new \yii\web\ServerErrorHttpException('The *workspace* folder could not be found - please create it in your project root');
      }
      $elementsPath = realpath($workspacePath.'/elements');
      if ($elementsPath === false) {
          throw new \yii\web\ServerErrorHttpException('The *elements* folder could not be found - please create it in your workspace folder');
      }
      $modelJson = realpath($elementsPath.'/'.$this->ctype.'.json');
      if ($modelJson === false) {
          file_put_contents($elementsPath.'/'.$this->ctype.'.json', '{"key":"user","label":"User","tabs":[{"label":"Login","key":"login","visible":false,"groups":[{"label":"Login","key":"login","fields":["email","password","login"]}]},{"label":"User","key":"user","groups":[{"label":"User","key":"user","fields":["email","password","state"]}]}],"fields":[{"label":"Email address","key":"email","type":"textInput","visibleInGrid":true,"rules":[["required"],["email"],["string",{"max":128}]]},{"label":"Password","key":"password","type":"passwordInput","visibleInGrid":false,"rules":[["required"],["string",{"max":128}]],"transform":"md5"},{"label":"Login","key":"login","type":"submitButton","visibleInGrid":false}]}, {"label":"Auth-Key","key":"authKEy","type":"text","visibleInGrid":false}]}');
      }

      //\Yii::$app->cache->flush();

      $usersProvider = new CrelishJsonDataProvider('user');
      $users = $usersProvider->rawAll();

      if(sizeof($users) == 0) {
        // Generate default admin.
        $adminUser = new CrelishDynamicJsonModel(['email', 'password', 'login', 'state'], ['ctype' => 'user']);
        $adminUser->email = 'admin@local.host';
        $adminUser->login = 'admin@local.host';
        $adminUser->password = 'basta!';
        $adminUser->state = 1;
        $adminUser->authKey = \Yii::$app->security->generateRandomString();
        $adminUser->save();
      }
  }

  /**
   * [actionLogin description].
   *
   * @return [type] [description]
   */
  public function actionLogin()
  {
    // Turn away if logged in.
    if (!\Yii::$app->user->isGuest) {
      return $this->redirect( Url::to(['/crelish/content/index']) );
    }

    // Validate data and login the user in case of post request.
    if (\Yii::$app->request->post()) {
      if (CrelishUser::crelishLogin(\Yii::$app->request->post('CrelishDynamicJsonModel'))) {
        return $this->redirect( Url::to(['/crelish/content/index']) );
      }
    }

    // Prepare rendered form.
    $content = $this->buildForm('login', ['id' => 'login-form', 'outerClass' => '', 'groupClass' => 'c-card gc-bc--palette-clouds gc-bs--soft', 'tabs' => ['login' => ['visible' => true], 'user' => ['visible' => false]]]);

    // Render it all with twig.
    return $this->render('login.twig', [
      'content' => $content,
      'ctype' => $this->ctype,
      'uuid' => $this->uuid,
    ]);
  }

  /**
   * [actionLogout description].
   *
   * @return [type] [description]
   */
  public function actionLogout()
  {
      \Yii::$app->user->logout();

      return $this->goHome();
  }
}
