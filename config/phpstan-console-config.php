<?php

declare(strict_types=1);

return [
  'phpstan' => [
    'application_type' => \yii\console\Application::class,
  ],
  'components' => [
    'db' => [
      'class' => \yii\db\Connection::class,
      'dsn' => 'sqlite::memory:',
    ],
    // Console-specific components
  ],
];