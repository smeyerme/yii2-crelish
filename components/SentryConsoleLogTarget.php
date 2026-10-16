<?php

namespace giantbits\crelish\components;

use yii\helpers\VarDumper;
use yii\log\Target;

/**
 * Hands what a console command logs to SentryConsoleLogs, which sends it with the record
 * of the run.
 */
class SentryConsoleLogTarget extends Target
{
  public ?SentryConsoleLogs $logs = null;

  public function init(): void
  {
    parent::init();
    $this->setLevels(['error', 'warning', 'info']);
    $this->except = ['yii\*'];
    $this->logVars = [];
    $this->exportInterval = 1;
  }

  public function export(): void
  {
    foreach ($this->messages as [$text, $level, $category]) {
      if ($text instanceof \Throwable) {
        $text = get_class($text) . ': ' . $text->getMessage();
      } elseif (!is_string($text)) {
        $text = VarDumper::export($text);
      }
      $this->logs?->collect(mb_substr($text, 0, 4000), $level, (string)$category);
    }
  }
}
