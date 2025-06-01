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
   * Crelish CMS Module for Yii2
   *
   * @author Qiang Xue <qiang.xue@gmail.com>
   * @since 2.0
   */
  class Module extends \yii\base\Module
  {
    
    /**
     * Path to data storage
     * @var string
     */
    private string $dataPath;
    
    /**
     * Default language for the application
     * @var string
     */
    public string $defaultLanguage;
    
    /**
     * Theme name
     * @var string
     */
    public string $theme = 'default';
    
    /**
     * Default entry point configuration
     * @var array
     */
    public array $entryPoint = [
      'ctype' => 'page',
      'path' => 'home',
      'slug' => 'home'
    ];
    
    /**
     * @inheritdoc
     */
    public function init(): void
    {
	    parent::init();
	    
	    if (Yii::$app instanceof Application) {
		    $this->controllerNamespace = 'giantbits\crelish\commands';
	    } else {
        // For web applications, set the default namespace
        $this->controllerNamespace = 'giantbits\crelish\controllers';
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
    
    /**
     * Set module dependencies
     */
    private function setDependencies(): void
    {
      // Implementation needed
    }
    
    /**
     * Build the controller map
     */
    private function buildControllerMap(): void
    {
      if (empty($this->controllerMap)) {
        $this->controllerMap = [];
      }
    }
    
    /**
     * Process language settings
     */
    private function processLanguage(): void
    {
      //Yii::$app->sourceLanguage = 'en-US';
      Yii::$app->params['defaultLanguage'] = 'de';
    }
    
    /**
     * @inheritdoc
     */
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
        // Preserve the PjaxAsset override if it exists
        $pjaxAssetConfig = isset(Yii::$app->assetManager->bundles['yii\widgets\PjaxAsset']) 
          ? Yii::$app->assetManager->bundles['yii\widgets\PjaxAsset'] 
          : null;
        
        Yii::$app->assetManager->bundles = [];

        Yii::$app->assetManager->bundles['yii\web\JqueryAsset'] = [
          'js' => [
            YII_DEBUG ? 'jquery.js' : 'jquery.min.js'
          ]
        ];
        
        // Restore PjaxAsset override if it was set
        if ($pjaxAssetConfig !== null) {
          Yii::$app->assetManager->bundles['yii\widgets\PjaxAsset'] = $pjaxAssetConfig;
        }
      }
    }
    
    /**
     * Check if current IP has access to the module
     * @return bool Whether access is allowed
     */
    protected function checkAccess(): bool
    {
      $ip = Yii::$app->getRequest()->getUserIP();
      foreach ($this->allowedIPs as $filter) {
        if ($filter === '*' || $filter === $ip || (($pos = strpos($filter, '*')) !== false && !strncmp($ip, $filter, $pos))) {
          return true;
        }
      }
      foreach ($this->allowedHosts as $hostname) {
        $filter = gethostbyname($hostname);
        if ($filter === $ip) {
          return true;
        }
      }
      Yii::warning('Access to debugger is denied due to IP address restriction. The requesting IP address is ' . $ip, __METHOD__);
      return false;
    }
    
    /**
     * Register module translations
     */
    private function registerTranslations(): void
    {
      Yii::$app->i18n->translations['modules/crelish/*'] = [
        'class' => 'yii\i18n\PhpMessageSource',
        'sourceLanguage' => 'en-US',
        'basePath' => __DIR__ . '/messages',
      ];
    }
  }
