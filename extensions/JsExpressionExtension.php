<?php
	
	namespace giantbits\crelish\extensions;

  use yii\web\JsExpression;
  use Twig\Extension\AbstractExtension;
  use Twig\TwigFunction;

  class JsExpressionExtension extends AbstractExtension
  {
    public function getFunctions()
    {
      return [
        new TwigFunction('js', [$this, 'createJsExpression']),
      ];
    }

    public function createJsExpression($expression)
    {
      return new JsExpression($expression);
    }
  }
