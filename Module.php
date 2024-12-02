<?php
  /**
   * @link http://www.yiiframework.com/
   * @copyright Copyright (c) 2008 Yii Software LLC
   * @license http://www.yiiframework.com/license/
   */
  
  namespace giantbits\crelish;
  
  use Yii;
  use yii\console\Application;

  /**
   * The Yii Debug Module provides the debug toolbar and debugger
   *
   * @author Qiang Xue <qiang.xue@gmail.com>
   * @since 2.0
   */
  class Module extends \yii\base\Module
  {
    
    /**
     * [$dataPath description]
     * @var [type]
     */
    private $dataPath;
    
    /**
     * [$defaultLanguage description]
     * @var [type]
     */
    public $defaultLanguage;
    
    /**
     * [$theme description]
     * @var string
     */
    public $theme = 'default';
    
    /**
     * [$entryPoint description]
     * @var string
     */
    public $entryPoint = [
      'ctype' => 'page',
      'path' => 'home',
      'slug' => 'home'
    ];
    
    /**
     * @inheritdoc
     */
    public function init()
    {
	    parent::init();
	    
	    if (\Yii::$app instanceof Application) {
		    $this->controllerNamespace = 'giantbits\crelish\commands';
	    }
      
      Yii::setAlias('@crelish', '@app/vendor/giantbits/yii2-crelish');
      Yii::setAlias('@workspace', '@app/workspace');
      Yii::setAlias('@workspace/actions', '@workspace/actions');
      
			Yii::setAlias('@bower', '@vendor/bower-asset');
      Yii::setAlias('@npm',  '@vendor/npm-asset');
			
			Yii::$app->params['bsVersion'] = '5.x';
      
      // Detect language.
      $this->processLanguage();
      $this->buildControllerMap();
      $this->setDependencies();
      $this->registerTranslations();
    }
    
    private function setDependencies()
    {

    }
    
    private function buildControllerMap(): void
    {
      $this->controllerMap = [];
    }
    
    /**
     * [processLanguage description]
     * @return [type] [description]
     */
    private function processLanguage(): void
    {
      Yii::$app->sourceLanguage = 'en-US';
      Yii::$app->params['defaultLanguage'] = 'de-CH';
    }
    
    public function beforeAction($action): bool
    {
      if (!parent::beforeAction($action)) {
        return false;
      }
      
      $this->resetGlobalSettings();
      
      return true;
    }
    
    /**
     * Resets potentially incompatible global settings done in app config.
     */
    protected function resetGlobalSettings(): void
    {
      if (Yii::$app instanceof \yii\web\Application) {
        Yii::$app->assetManager->bundles = [];
      }
    }
    
    /**
     * [checkAccess description]
     * @return [type] [description]
     */
    protected function checkAccess(): bool
    {
      $ip = Yii::$app->getRequest()->getUserIP();
      foreach ($this->allowedIPs as $filter) {
        if ($filter === '*' || $filter === $ip || (($pos = strpos($filter, '*')) !== FALSE && !strncmp($ip, $filter, $pos))) {
          return TRUE;
        }
      }
      foreach ($this->allowedHosts as $hostname) {
        $filter = gethostbyname($hostname);
        if ($filter === $ip) {
          return TRUE;
        }
      }
      Yii::warning('Access to debugger is denied due to IP address restriction. The requesting IP address is ' . $ip, __METHOD__);
      return FALSE;
    }
    
    private function registerTranslations(): void
    {
      Yii::$app->i18n->translations['modules/crelish/*'] = [
        'class' => 'yii\i18n\PhpMessageSource',
        'sourceLanguage' => 'en-US',
        'basePath' => __DIR__ . '/messages',
      ];
    }
  }
