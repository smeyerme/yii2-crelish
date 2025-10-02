<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use yii\web\Controller;
use yii\filters\AccessControl;

/**
 * TrackController handles analytics tracking endpoints
 *
 * This controller provides endpoints for various tracking operations
 * including click tracking for links, ads, and other interactive elements.
 *
 * All tracking endpoints are publicly accessible (no authentication required).
 */
class TrackController extends CrelishBaseController
{
    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false; // Disable CSRF for ping requests

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['click'],
                        'roles' => ['?', '@'], // Allow both guests (?) and authenticated users (@)
                    ],
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function actions(): array
    {
        return [
            'click' => [
                'class' => 'giantbits\crelish\actions\TrackClickAction',
                // Optional: Customize default settings
                // 'tokenValidityWindow' => 3600,  // 1 hour
                // 'maxClicksPerElement' => 10,
                // 'rateLimitWindow' => 300,       // 5 minutes
            ],
        ];
    }
}