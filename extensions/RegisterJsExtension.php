<?php
	
	namespace giantbits\crelish\extensions;
	
	use Twig\Extension\AbstractExtension;
	use Twig\TwigFilter;
	use yii\web\View;
	
	class RegisterJsExtension extends AbstractExtension
	{
		public function getFilters()
		{
			return [
				new TwigFilter('registerJs', [$this, 'registerJsFilter'], ['is_safe' => ['html']]),
			];
		}
		
		public function registerJsFilter($js)
		{
			// Fix the nonce handling
			$key = null; // Use default key (md5 of js)
			$options = [];
			
			if(!empty(\Yii::$app->controller->nonce)) {
				$options['nonce'] = \Yii::$app->controller->nonce;
			}
			
			// Register the JS with proper parameters: js content, position, key, options
			\Yii::$app->view->registerJs($js, View::POS_END, $key, $options);
			
			return '';
		}
	}
