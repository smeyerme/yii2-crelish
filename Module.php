<?php

namespace giantbits\crelish;

use Yii;
use yii\base\Module as BaseModule;
use yii\console\Application as ConsoleApplication;
use yii\web\Application as WebApplication;

/**
 * Crelish CMS Module for Yii2
 * 
 * Main module class that handles initialization and configuration
 * of the Crelish CMS system.
 */
class Module extends BaseModule
{
    /**
     * @var string Theme name for the module
     */
    public string $theme = 'default';
    
    /**
     * @var array Default entry point configuration
     */
    public array $entryPoint = [
        'ctype' => 'page',
        'path' => 'home',
        'slug' => 'home'
    ];
    
    /**
     * @var array List of IPs that are allowed to access this module
     */
    public array $allowedIPs = ['127.0.0.1', '::1'];
    
    /**
     * @var array List of hostnames that are allowed to access this module
     */
    public array $allowedHosts = [];
    
    /**
     * @var string Default language for the application
     */
    public string $defaultLanguage = 'en';
    
    /**
     * @var array Asset bundles to preserve during reset
     */
    private array $preservedAssetBundles = [
        'yii\widgets\PjaxAsset'
    ];
    
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        
        $this->configureControllerNamespace();
        $this->registerAliases();
        $this->configureApplication();
        $this->registerTranslations();
    }
    
    /**
     * Configure controller namespace based on application type
     */
    private function configureControllerNamespace(): void
    {
        if (Yii::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'giantbits\crelish\commands';
        } else {
            $this->controllerNamespace = 'giantbits\crelish\controllers';
        }
    }
    
    /**
     * Register module aliases
     */
    private function registerAliases(): void
    {
        Yii::setAlias('@crelish', '@app/vendor/giantbits/yii2-crelish');
        Yii::setAlias('@workspace', '@app/workspace');
        Yii::setAlias('@workspace/actions', '@workspace/actions');
        Yii::setAlias('@bower', '@vendor/bower-asset');
        Yii::setAlias('@npm', '@vendor/npm-asset');
    }
    
    /**
     * Configure application-wide settings
     */
    private function configureApplication(): void
    {
        // Set Bootstrap version for compatibility
        Yii::$app->params['bsVersion'] = '5.x';
        
        // Set default language if not already set
        if (!isset(Yii::$app->params['defaultLanguage'])) {
            Yii::$app->params['defaultLanguage'] = $this->defaultLanguage;
        }
    }
    
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        
        if (Yii::$app instanceof WebApplication) {
            $this->resetGlobalSettings();
        }
        
        return true;
    }
    
    /**
     * Resets potentially incompatible global settings done in app config.
     * Preserves important asset bundle configurations.
     */
    protected function resetGlobalSettings(): void
    {
        if (!Yii::$app instanceof WebApplication) {
            return;
        }
        
        $assetManager = Yii::$app->assetManager;
        
        // Preserve specified asset bundles
        $preservedBundles = [];
        foreach ($this->preservedAssetBundles as $bundleName) {
            if (isset($assetManager->bundles[$bundleName])) {
                $preservedBundles[$bundleName] = $assetManager->bundles[$bundleName];
            }
        }
        
        // Reset bundles
        $assetManager->bundles = [];
        
        // Configure default jQuery asset
        $assetManager->bundles['yii\web\JqueryAsset'] = [
            'js' => [
                YII_DEBUG ? 'jquery.js' : 'jquery.min.js'
            ]
        ];
        
        // Restore preserved bundles
        foreach ($preservedBundles as $name => $config) {
            $assetManager->bundles[$name] = $config;
        }
    }
    
    /**
     * Check if current IP has access to the module
     * 
     * @param string|null $ip IP address to check (defaults to current user IP)
     * @return bool Whether access is allowed
     */
    protected function checkAccess(?string $ip = null): bool
    {
        if ($ip === null) {
            $ip = Yii::$app->getRequest()->getUserIP();
        }
        
        // Check allowed IPs
        foreach ($this->allowedIPs as $filter) {
            if ($this->matchIP($ip, $filter)) {
                return true;
            }
        }
        
        // Check allowed hosts
        foreach ($this->allowedHosts as $hostname) {
            $hostIP = gethostbyname($hostname);
            if ($hostIP === $ip) {
                return true;
            }
        }
        
        Yii::warning("Access to module denied for IP: {$ip}", __METHOD__);
        return false;
    }
    
    /**
     * Check if an IP matches a filter pattern
     * 
     * @param string $ip IP address to check
     * @param string $filter Filter pattern (supports wildcards)
     * @return bool Whether the IP matches the filter
     */
    private function matchIP(string $ip, string $filter): bool
    {
        if ($filter === '*' || $filter === $ip) {
            return true;
        }
        
        $pos = strpos($filter, '*');
        if ($pos !== false) {
            return strncmp($ip, $filter, $pos) === 0;
        }
        
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
            'fileMap' => [
                'modules/crelish/main' => 'main.php',
            ],
        ];
    }
    
    /**
     * Translate a message
     * 
     * @param string $category Message category
     * @param string $message Message to translate
     * @param array $params Message parameters
     * @param string|null $language Target language
     * @return string Translated message
     */
    public static function t(string $category, string $message, array $params = [], ?string $language = null): string
    {
        return Yii::t('modules/crelish/' . $category, $message, $params, $language);
    }
}