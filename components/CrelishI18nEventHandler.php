<?php
namespace giantbits\crelish\components;

use yii\i18n\MissingTranslationEvent;

/**
 *
 */
class CrelishI18nEventHandler
{

  /**
   * [handleMissingTranslation description]
   * @param  MissingTranslationEvent $event [description]
   * @return [type]                         [description]
   */
  public static function handleMissingTranslation(MissingTranslationEvent $event)
  {
      $event->translatedMessage = "@MISSING: {$event->category}.{$event->message} FOR LANGUAGE {$event->language} @";
  }
}
