<?php

namespace giantbits\crelish\components;

use yii\log\Target;

/**
 * Hands Yii's database profiling messages to SentryTracing, which turns each query of a
 * traced transaction into a span.
 */
class SentryTracingLogTarget extends Target
{
  public ?SentryTracing $tracing = null;

  public function init(): void
  {
    parent::init();
    $this->setLevels(['profile']);
    $this->categories = ['yii\db\Command::query', 'yii\db\Command::execute'];
    $this->logVars = [];
    $this->exportInterval = 1;
  }

  public function export(): void
  {
    foreach ($this->messages as $message) {
      $this->tracing?->profileMessage($message);
    }
  }
}
