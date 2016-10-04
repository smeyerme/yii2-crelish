<?php
namespace giantbits\crelish\plugins\widgetconnector;

use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Component;
use yii\helpers\Json;

class WidgetConnectorContentProcessor extends Component
{
  public $data;

  public static function processData($caller, $key, $data, &$processedData)
  {

    //$include = new CrelishJsonDataProvider('asset', [], $data['uuid']);
    //$processedData[$key] = $include->one();

    $html = <<<EOT
<section class="image-square right">
  <div class="col-md-6 image">
    <div class="background-image-holder fadeIn" style="background-color: #474d4c;">
      <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3189.8830073463714!2d7.516804343627841!3d47.20765786527276!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x4791d80ed450cfbd%3A0x7d5b64466bbcfd78!2sEibenweg+1%2C+4500+Solothurn%2C+Switzerland!5e0!3m2!1sen!2sde!4v1475586285704" width="100%" height="100%" frameborder="0" style="border:0" allowfullscreen></iframe>
    </div>
  </div>
  <div class="col-md-6 content">
    <form action="test" class="form-email" data-success="Danke für Deine Nachricht. Ich werde mich so schnell wie möglich bei Dir melden." data-error="Bitte fülle alle Felder korrekt aus.">
      <h3>Kontaktformular</h3>
      <p>Sende mir einfach eine Nachricht.</p>
      <input type="text" class="validate-required field-error" name="name" placeholder="  Dein Name">
      <input type="text" class="validate-required validate-email field-error" name="email" placeholder="Deine E-Mail Adresse">
      <textarea class="validate-required field-error" name="message" rows="4" placeholder="Deine Nachricht an mich"></textarea>
      <button type="submit">Nachricht senden</button>
    </form>
  </div>
</section>
EOT;
    $processedData[$key] = $html;

  }
}
