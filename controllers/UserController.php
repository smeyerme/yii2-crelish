<?php
  
  namespace giantbits\crelish\controllers;
  
  use giantbits\crelish\components\CrelishBaseController;
  use giantbits\crelish\components\CrelishUser;
  use giantbits\crelish\components\CrelishDataProvider;
  use giantbits\crelish\components\CrelishDynamicModel;
  use Underscore\Types\Arrays;
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
    $workspacePath = realpath(\Yii::getAlias('@webroot') . '/../workspace');
    if ($workspacePath === false) {
      throw new \yii\web\ServerErrorHttpException('The *workspace* folder could not be found - please create it in your project root');
    }
    $elementsPath = realpath($workspacePath . '/elements');
    if ($elementsPath === false) {
      throw new \yii\web\ServerErrorHttpException('The *elements* folder could not be found - please create it in your workspace folder');
    }
    $modelJson = realpath($elementsPath . '/' . $this->ctype . '.json');
      
    if ($modelJson === false) {
      file_put_contents($elementsPath . '/' . $this->ctype . '.json', '{"key":"user","label":"User","tabs":[{"label":"Login","key":"login","visible":false,"groups":[{"label":"Login","key":"login","fields":["email","password","login"]}]},{"label":"User","key":"user","groups":[{"label":"User","key":"user","fields":["email","password","state"]}]}],"fields":[{"label":"Email address","key":"email","type":"textInput","visibleInGrid":true,"rules":[["required"],["email"],["string",{"max":128}]]},{"label":"Password","key":"password","type":"passwordInput","visibleInGrid":false,"rules":[["required"],["string",{"max":128}]],"transform":"hash"},{"label":"Login","key":"login","type":"submitButton","visibleInGrid":false}, {"label":"Auth-Key","key":"authKey","type":"text","visibleInGrid":false}]}');
    }
      
    //\Yii::$app->cache->flush();
      
    $usersProvider = new CrelishDataProvider('user');
    $users = $usersProvider->rawAll();
      
    if (sizeof($users) == 0) {
      // Generate default admin.
      $adminUser = new CrelishDynamicModel(['email', 'password', 'login', 'state', 'role'], ['ctype' => 'user']);
      $adminUser->email = 'admin@local.host';
      $adminUser->password = 'basta!';
      $adminUser->state = 1;
      $adminUser->authKey = \Yii::$app->security->generateRandomString();
      $adminUser->role = 9;
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
      if (!\Yii::$app->user->isGuest && \Yii::$app->user->idendity->role == 9) {
        return $this->redirect(Url::to(['/crelish/content/index']));
    }
      
    $model = new CrelishDynamicModel(['email', 'password'], ['ctype' => 'user']);
      
    // Validate data and login the user in case of post request.
    if (\Yii::$app->request->post()) {
      if (CrelishUser::crelishLogin(\Yii::$app->request->post('CrelishDynamicModel'))) {
          return $this->redirect(Url::to(['/crelish/content/index']));
      }
    }
      
    // Render it all with twig.
    return $this->render('login.twig', [
      'model' => $model,
      'ctype' => $this->ctype,
      'uuid' => $this->uuid,
    ]);
  }
    
  public function actionIndex()
  {
    $this->layout = 'crelish.twig';
    $filter = null;
    if (!empty($_GET['cr_content_filter'])) {
      $filter = ['freesearch' => $_GET['cr_content_filter']];
    }
      
      $modelProvider = new CrelishDataProvider('user', ['filter' => $filter], null, true);
    $checkCol = [
      [
          'class' => 'giantbits\crelish\components\CrelishCheckboxColumn',
      ]
    ];
      
      $columns = $modelProvider->columns;
      
      $columns = Arrays::invoke($columns, function ($item) {
        
        if (key_exists('attribute', $item) && $item['attribute'] === 'state') {
          $item['format'] = 'raw';
          $item['label'] = 'Status';
          $item['value'] = function ($data) {
            switch ($data['state']) {
              case 1:
                $state = 'Draft';
                break;
              case 2:
                $state = 'Online';
                break;
              case 3:
                $state = 'Archived';
                break;
              default:
                $state = 'Offline';
            };
            
            return $state;
          };
        }
  
        if (key_exists('attribute', $item) && $item['attribute'] === 'role') {
          $item['format'] = 'raw';
          $item['label'] = 'Rolle / Typ';
          $item['value'] = function ($data) {
            switch ($data['role']) {
              case 1:
                $state = 'Registriert';
                break;
              case 2:
                $state = 'Basis';
                break;
              case 4:
                $state = 'Basis+';
                break;
              case 6:
                $state = 'Premium';
                break;
              case 8:
                $state = 'Premium+';
                break;
              case 9:
                $state = 'Admin';
                break;
              default:
                $state = 'Gast';
            };
      
            return $state;
          };
        }
  
        if (key_exists('attribute', $item) && $item['attribute'] === 'activationDate') {
          $item['format'] = 'raw';
          $item['label'] = 'Datum Aktivierung';
          $item['value'] = function ($data) {
            return !empty($data['activationDate']) ? strftime("%d.%m.%Y", $data['activationDate']) : '';
          };
        }
        
        return $item;
      });
      
    $rowOptions = function ($model, $key, $index, $grid) {
      return ['onclick' => 'location.href="update?uuid=' . $model['uuid'] . '";'];
    };
      
    return $this->render('index.twig', [
      'dataProvider' => $modelProvider->raw(),
      'filterProvider' => $modelProvider->getFilters(),
      'columns' => $columns,
      'ctype' => $this->ctype,
      'rowOptions' => $rowOptions
    ]);
  }
    
  public function actionUpdate()
  {
    $this->layout = 'crelish.twig';
      
    $content = $this->buildForm();
      
    return $this->render('update.twig', [
      'content' => $content,
      'ctype' => 'user',
        'uuid' => $this->uuid
    ]);
  }
    
  public function actionCreate()
  {
    $this->layout = 'crelish.twig';
      
      $content = $this->buildForm('create');
      
    return $this->render('create.twig', [
      'content' => $content,
      'ctype' => 'user',
      'uuid' => $this->uuid,
    ]);
  }
    
  public function actionDelete()
    {
      $ctype = 'user';
      $uuid = \Yii::$app->request->get('uuid');
      
      $model = new CrelishDynamicModel([], ['ctype' => $ctype, 'uuid' => $uuid]);
      $model->delete();
      
      \Yii::$app->cache->flush();
      
      $this->redirect('/crelish/user/index');
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
