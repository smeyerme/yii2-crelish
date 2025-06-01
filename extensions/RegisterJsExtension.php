<?php
	
	namespace giantbits\crelish\extensions;
	
	use MatthiasMullie\Minify\JS;
	use Twig\Extension\AbstractExtension;
	use Twig\TwigFilter;
	use yii\web\View;
	use Yii;
	
	class RegisterJsExtension extends AbstractExtension
	{
		public function getFilters()
		{
			return [
				new TwigFilter('registerJs', [$this, 'registerJsFilter'], ['is_safe' => ['html']]),
			];
		}
		
		public function registerJsFilter($js, $position = View::POS_END, $key = null)
		{
			$options = [];
			
			// Add nonce if available
			if(!empty(Yii::$app->controller->nonce)) {
				$options['nonce'] = Yii::$app->controller->nonce;
			}
			
			// Minify JS in production mode
			if (!YII_DEBUG && !YII_ENV_DEV) {
				try {
					$minifier = new JS();
					$minifier->add($js);
					$js = $minifier->minify();
				} catch (\Exception $e) {
					// In production, silently continue with unminified JS
					// Logging might not be available in all production configurations
				}
			}
			
			// Register the JS with proper parameters
			Yii::$app->view->registerJs($js, $position, $key, $options);
			
			return '';
		}
	}
