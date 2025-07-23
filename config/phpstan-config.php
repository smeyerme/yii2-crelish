<?php

declare(strict_types=1);

return [
  'phpstan' => [
    'application_type' => \yii\web\Application::class,
  ],
  'components' => [
    'db' => [
      'class' => \yii\db\Connection::class,
    ],
    'user' => [
      'class' => \yii\web\User::class,
      'identityClass' => \app\models\User::class,
    ],
    'mailer' => [
      'class' => \yii\mail\MailerInterface::class,
    ],
    // Add your custom components here
    'customService' => [
      'class' => \app\services\CustomService::class,
    ],
  ],
  'container' => [
    'definitions' => [
      //'logger' => \Psr\Log\LoggerInterface::class,
      //'cache' => \yii\caching\CacheInterface::class,
    ],
    'singletons' => [
      //'eventDispatcher' => \app\services\EventDispatcher::class,
    ],
  ],
];