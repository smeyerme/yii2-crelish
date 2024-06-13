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
			
			$nonceArray = null;
			
			if(!empty(\Yii::$app->controller->nonce)) {
				$nonceArray =  ['nonce' => \Yii::$app->controller->nonce];
			}
			
			\Yii::$app->view->registerJs($js, View::POS_END, $nonceArray);
		}
	}
