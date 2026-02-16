<?php

	namespace giantbits\crelish\actions;

	use League\Glide\ServerFactory;
	use Yii;
	use yii\base\Action;
	use yii\web\Response;
	use yii\base\NotSupportedException;

	/**
	 * @author Eugene Terentev <eugene@terentev.net>
	 */
	class GlideAction extends Action
	{
		/**
		 * @return Response
		 * @throws NotSupportedException
		 */
		public function run()
		{
			$params = Yii::$app->request->getQueryParams();
			$params['fm'] = 'webp';
			$path = $params['path'] ?? null;
			$response = Yii::$app->response;

			if(empty($path)) {
				$response->statusCode = 404;
				$response->format = Response::FORMAT_RAW;
				$response->content = '';
				return $response;
			}

			$server = ServerFactory::create([
				'source' => Yii::getAlias('@app/web'),
				'cache' => Yii::getAlias('@runtime/glide'),
				'presets' => Yii::$app->params['crelish']['glide_presets']
			]);

			$checkFile = Yii::getAlias('@app/web') . (str_starts_with($path, '/') ? $path : '/' . $path);

			if(!file_exists($checkFile)) {
				$response->statusCode = 404;
				$response->format = Response::FORMAT_RAW;
				$response->content = '';
				return $response;
			}

			try {
				$path = $server->makeImage($path, $params);

				$response->format = Response::FORMAT_RAW;
				$response->headers->add('Content-Type', $server->getCache()->mimeType($path));
				$response->headers->add('Content-Length', $server->getCache()->fileSize($path));
				$response->headers->add('Cache-Control', 'max-age=31536000, public');
				$response->headers->add('Expires', (new \DateTime('UTC + 1 year'))->format('D, d M Y H:i:s \G\M\T'));

				$response->stream = $server->getCache()->readStream($path);

				return $response;
			} catch (\Exception $e) {
				throw new NotSupportedException($e->getMessage());
			}
		}
	}