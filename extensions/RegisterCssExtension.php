<?php
	
	namespace giantbits\crelish\extensions;
	
	use Twig\Extension\AbstractExtension;
	use Twig\TwigFilter;
	
	class RegisterCssExtension extends AbstractExtension
	{
		public function getFilters()
		{
			return [
				new TwigFilter('registerCss', [$this, 'registerCssFilter'], ['is_safe' => ['html']]),
			];
		}
		
		public function registerCssFilter($css)
		{
			\Yii::$app->view->registerCss($css, ['nonce' => \Yii::$app->controller->nonce]);
		}
	}
