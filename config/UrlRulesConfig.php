<?php

namespace giantbits\crelish\config;

/**
 * URL rules configuration for Crelish CMS
 */
class UrlRulesConfig
{
    /**
     * Get all URL rules
     * 
     * @return array URL rules configuration
     */
    public static function getRules(): array
    {
        return array_merge(
            self::getSitemapRules(),
            self::getApiRules(),
            self::getCrelishRules(),
            self::getGenericRules()
        );
    }
    
    /**
     * Get sitemap-related URL rules
     */
    private static function getSitemapRules(): array
    {
        return [
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'sitemap-<lang:\w+>',
                'route' => 'sitemap/language',
                'suffix' => '.xml',
            ],
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'sitemap',
                'route' => 'sitemap/index',
                'suffix' => '.xml',
            ],
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'sitemap-style',
                'route' => 'sitemap/style',
                'suffix' => '.xsl',
            ],
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'sitemap-ping',
                'route' => 'sitemap/ping',
            ],
        ];
    }
    
    /**
     * Get API-related URL rules
     */
    private static function getApiRules(): array
    {
        return [
            // Document rules
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'document/secure/<id:[\w\-]+>',
                'route' => 'document/secure'
            ],
            // Legacy API routes (exclude versioned APIs like /api/v1/, /api/v2/, etc.)
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'api/<action:(?!v\d+)[\w\-]+>',
                'route' => 'api/<action>'
            ],
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'api/<action:(?!v\d+)[\w\-]+>/<id:[\w\-]+>',
                'route' => 'api/<action>'
            ],
            // REST API rules
            [
                'class' => 'yii\rest\UrlRule',
                'controller' => 'user',
                'tokens' => ['{uuid}' => '<uuid:\\d[\\d,]*>']
            ],
            // Crelish API routes
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'crelish-api/auth/login',
                'route' => 'crelish-api/auth/login',
                'verb' => 'POST',
            ],
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'crelish-api/content/<type:[\w\-]+>',
                'route' => 'crelish-api/content/index',
            ],
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'crelish-api/content/<type:[\w\-]+>/<id:[\w\-]+>',
                'route' => 'crelish-api/content/view',
            ],
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'crelish-api/content/<type:[\w\-]+>',
                'route' => 'crelish-api/content/create',
                'verb' => 'POST',
            ],
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'crelish-api/content/<type:[\w\-]+>/<id:[\w\-]+>',
                'route' => 'crelish-api/content/update',
                'verb' => 'PUT',
            ],
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'crelish-api/content/<type:[\w\-]+>/<id:[\w\-]+>',
                'route' => 'crelish-api/content/delete',
                'verb' => 'DELETE',
            ],
        ];
    }
    
    /**
     * Get Crelish admin URL rules
     */
    private static function getCrelishRules(): array
    {
        return [
            // Site routes
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'site/<action:[\w\-]+>',
                'route' => 'site/<action>'
            ],
            // Click tracking endpoint (must come before generic crelish rules)
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'crelish/track/click',
                'route' => 'crelish/track/click',
                'verb' => ['GET', 'POST']
            ],
            // Crelish base rule
            [
                'class' => 'giantbits\crelish\components\CrelishBaseUrlRule'
            ],
            // Crelish admin routes
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'crelish',
                'route' => 'crelish/dashboard/index'
            ],
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'crelish/<controller:[\w\-]+>/<action:[\w\-]+>',
                'route' => 'crelish/<controller>/<action>'
            ],
        ];
    }
    
    /**
     * Get generic URL rules
     */
    private static function getGenericRules(): array
    {
        return [
            // User routes
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => 'user/<action:[\w\-]+>',
                'route' => 'user/<action>'
            ],
            // Generic routes
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => '<controller:[\w\-]+>/<action:[\w\-]+>/<id:[\w\-]+>',
                'route' => '<controller>/<action>'
            ],
            // Localized routes
            [
                'class' => 'yii\web\UrlRule',
                'pattern' => '<lang:[\w\-]+>/<controller:[\w\-]+>/<action:[\w\-]+>/<id:[\w\-]+>',
                'route' => '<controller>/<action>'
            ],
        ];
    }
}