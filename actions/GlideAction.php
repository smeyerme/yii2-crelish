<?php
	
	namespace giantbits\crelish\actions;
	
	use League\Glide\ServerFactory;
	use Yii;
	use yii\base\Action;
	use yii\web\Response;
	use yii\base\NotSupportedException;
	use yii\web\BadRequestHttpException;
	use yii\web\NotFoundHttpException;
	
	/**
	 * @author Eugene Terentev <eugene@terentev.net>
	 */
	class GlideAction extends Action
	{
		/**
		 * @param $path
		 * @return Response
		 * @throws BadRequestHttpException
		 * @throws NotFoundHttpException
		 * @throws NotSupportedException
		 */
		public function run()
		{
			
			$params = Yii::$app->request->getQueryParams();
			$params['fm'] = 'webp';
			$path = $params['path'];
			
			if(empty($path)) {
				throw new NotFoundHttpException('The requested media does not exist.');
			}
			
			$server = ServerFactory::create([
				'source' => Yii::getAlias('@app/web'),
				'cache' => Yii::getAlias('@runtime/glide'),
				'presets' => Yii::$app->params['crelish']['glide_presets']
			]);
			
			$checkFile = Yii::getAlias('@app/web') . str_starts_with($path, '/') ? $path : '/' . $path;
			
			if(!file_exists($checkFile)) {
				throw new NotFoundHttpException('Requested media file does not exists.');
			}
			
			try {
				$response = Yii::$app->response;
				$path = $server->makeImage($path, $params);
				
				Yii::$app->response->format = Response::FORMAT_RAW;
				Yii::$app->response->headers->add('Content-Type', $server->getCache()->mimeType($path));
				Yii::$app->response->headers->add('Content-Length', $server->getCache()->fileSize($path));
				Yii::$app->response->headers->add('Cache-Control', 'max-age=31536000, public');
				Yii::$app->response->headers->add('Expires', (new \DateTime('UTC + 1 year'))->format('D, d M Y H:i:s \G\M\T'));
				
				$response->stream = $server->getCache()->readStream($path);
				
				return $response;
			} catch (\Exception $e) {
				throw new NotSupportedException($e->getMessage());
			}
		}
	}
