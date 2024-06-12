<?php
	
	namespace giantbits\crelish\components;
	
	use Yii;
	use yii\base\InvalidRouteException;
	use yii\bootstrap\ActiveForm;
	use yii\i18n\Formatter;
	use yii\web\Controller;
	use yii\helpers\Html;
	use yii\helpers\Url;
	use yii\web\NotFoundHttpException;
	
	class CrelishBaseController extends Controller
	{
		protected $ctype, $uuid, $filePath, $elementDefinition;
		public $model;
		public $nonce;
		
		public function init()
		{
			
			parent::init();
			
			\Yii::$app->view->title = ucfirst($this->id);
			
			if(!Yii::$app->user->isGuest) {
				$js = 'window.crelish = { "user": { "uuid": "' . Yii::$app->user->identity->uuid . '" }};';
				
				Yii::$app->view->registerJs($js, \yii\web\View::POS_HEAD);
			}
			
			$intelliCache = \Yii::$app->session->get('intellicache');
			if (!empty($intelliCache)) {
				
				$js = "$.ajax({
          url: '/crelish/settings/intellicache.html',
          data: {
              auth: '2e212e112e-2e12ea-vhrto4',
              uuid: '" . $this->uuid . "'
          },
          success: function(r){ console.info('Intellicache done'); }
      });";
				\Yii::$app->view->registerJs($js);
				\Yii::$app->session->remove('intellicache');
			}
			
			if ((\Yii::$app->user->isGuest || \Yii::$app->user->identity->role < 9)
				&& \Yii::$app->requestedRoute != 'crelish/user/login'
				&& \Yii::$app->requestedRoute != 'crelish/asset/glide') {
				return \Yii::$app->response->redirect(['/']);
			}
		}
		
		public function actions()
		{
			$actions = parent::actions();
			$controllerId = $this->id;
			$workspaceActionPath = Yii::getAlias("@workspace/actions/{$controllerId}");
			
			if (is_dir($workspaceActionPath)) {
				$files = scandir($workspaceActionPath);
				foreach ($files as $file) {
					if (strpos($file, 'Action.php') !== false) {
						$actionId = strtolower(str_replace('Action.php', '', $file));
						$actions[$actionId] = "workspace\\actions\\{$controllerId}\\" . str_replace('.php', '', $file);
					}
				}
			}
			
			return $actions;
		}
		
		public function runAction($id, $params = [])
		{
			try {
				return parent::runAction($id, $params);
			} catch (InvalidRouteException $e) {
				return $this->actionError();
			}
		}
		
		public function buildForm($action = 'default', $settings = [])
		{
			$formatter = new Formatter();
			$formatter->dateFormat = "dd.MM.yyyy";
			$formatter->nullDisplay = "";
			
			//default settings
			$defaults = [
				'id' => 'content-form',
				'outerClass' => 'gc-ptb--2',
				'groupClass' => 'c-card',
				'tabs' => []
			];
			
			$settings = $settings + $defaults;
			
			// Build form for type.
			$this->model = new CrelishDynamicModel([], [
				'ctype' => (!empty($settings['ctype'])) ? $settings['ctype'] : $this->ctype,
				'uuid' => (!empty($settings['uuid'])) ? $settings['uuid'] : $this->uuid
			]);
			
			if ($action !== 'default') {
				$this->model->scenario = $action;
			}
			
			// Save content if post request.
			if (!empty(\Yii::$app->request->post())
				&& !\Yii::$app->request->isAjax) {
				$oldData = [];
				
				// Load old data.
				if (!empty($this->model->uuid)) {
					$oldData = $this->model->attributes();
				}
				
				$attributes = $_POST['CrelishDynamicModel'] + $oldData;
				$this->model->attributes = $attributes;
				
				if ($this->model->validate()) {
					$this->model->save();
					
					\Yii::$app->session->setFlash('success', \Yii::t("crelish", 'Content saved successfully...'));
					
					if (!empty($_POST['save_n_return']) && $_POST['save_n_return'] == "1") {
						header('Location: ' . Url::to([
								\Yii::$app->controller->id . '/index',
								'ctype' => $this->ctype
							]));
						
						exit(0);
					}
					
					header('Location: ' . Url::to([
							\Yii::$app->controller->id . '/update',
							'ctype' => $this->ctype,
							'uuid' => $this->model->uuid
						]));
					exit(0);
				} else {
					$message = '';
					$errors = $this->model->errors;
					foreach ($errors as $error) {
						$message .= $error[0];
					}
					\Yii::$app->session->setFlash('error', 'Error saving item: ' . $message);
				}
			}
			
			// Last changes to model before form render. (Format date etc.)
			foreach ($this->model->fieldDefinitions->fields as $field) {
				if (!empty($field->format)) {
					
					if ($field->format == 'date') {
						$this->model->{$field->key} = $formatter->asDate($this->model->{$field->key});
					}
					
					if ($field->format == 'datetime') {
						$this->model->{$field->key} = $formatter->asDatetime($this->model->{$field->key}, 'dd.MM.yyyy HH:mm');
					}
				}
				
				if (!empty($field->defaultValue) && empty($this->uuid)) {
					$this->model->{$field->key} = $field->defaultValue;
				}
			}
			
			ob_start();
			$form = \kartik\widgets\ActiveForm::begin([
				'id' => $settings['id'],
				'options' => [
					'role' => 'presentation',
					'autocomplete' => 'off'
				]
			]);
			
			echo Html::beginTag("div", ['class' => $settings['outerClass']]);
			echo Html::beginTag("div", ['class' => 'o-grid o-grid--wrap o-grid--small-full']);
			
			// Get the tabs (there has to be at least one).
			$tabs = $this->model->fieldDefinitions->tabs;
			
			foreach ($tabs as $tab) {
				// Loop through tabs.
				//check tab overrides
				if (isset($settings['tabs'][$tab->key])) {
					foreach ($settings['tabs'][$tab->key] as $key => $val) {
						$tab->$key = $val;
					}
				}
				
				if (isset($tab->visible) && $tab->visible === FALSE) {
					continue;
				}
				
				foreach ($tab->groups as $group) {
					// Loop through groups.
					$groupSettings = (property_exists($group, 'settings')) ? $group->settings : NULL;
					$widthClass = (!empty($groupSettings->width)) ? 'o-grid__cell--width-' . $groupSettings->width : '';
					
					echo Html::beginTag('div', ['class' => 'o-grid__cell ' . $widthClass]);
					echo Html::beginTag('div', ['class' => $settings['groupClass']]);
					if (empty($groupSettings) || (property_exists($groupSettings, 'showLabel') && $groupSettings->showLabel !== FALSE) || !property_exists($groupSettings, 'showLabel')) {
						echo Html::tag('div', $group->label, ['class' => 'c-card__item c-card__item--brand']);
					}
					echo Html::beginTag('div', ['class' => 'c-card__item']);
					
					// Loop through model fields / attributes
					foreach ($this->model->fieldDefinitions->fields as $field) {
						
						// Prepare key for nested models.
						$keyName = (!empty($settings['prefix'])) ? $field->key : $field->key;
						
						if (!property_exists($field, 'type')) {
							$field->type = "textInput";
						}
						
						if (!in_array($field->key, $group->fields)) {
							continue;
						}
						
						// Build form fields.
						$fieldOptions = !empty($field->options) ? $field->options : [];
						$widgetOptions = !empty($field->widgetOptions) ? (array)$field->widgetOptions : [];
						$inputOptions = !empty($field->inputOptions) ? (array)$field->inputOptions : [];
						
						if (strpos($field->type, 'widget_') !== FALSE) {
							$widget = str_replace('widget_', '', $field->type);
							echo $form->field($this->model, $keyName)
								->widget($widget::className(), $widgetOptions)
								->label($field->label);
						} elseif ($field->type == 'dropDownList') {
							echo $form->field($this->model, $keyName)
								->{$field->type}((array)$field->items, (array)$fieldOptions)
								->label($field->label);
						} elseif ($field->type == 'checkboxList') {
							echo $form->field($this->model, $keyName)
								->{$field->type}((array)$field->items, (array)$fieldOptions)
								->label($field->label);
						} elseif ($field->type == 'submitButton') {
							echo Html::submitButton($field->label, array('class' => 'c-button c-button--brand c-button--block'));
						} elseif ($field->type == 'passwordInput') {
							unset($this->model[$keyName]);
							echo $form->field($this->model, $keyName, $inputOptions)
								->{$field->type}((array)$fieldOptions)
								->label($field->label);
						} else {
							$class = 'giantbits\crelish\plugins\\' . strtolower($field->type) . '\\' . ucfirst($field->type);
							// Check for crelish special fields.
							if (class_exists($class)) {
								echo $class::widget([
									'model' => $this->model,
									'formKey' => $keyName,
									'data' => $this->model[$field->key],
									'field' => $field
								]);
							} else {
								echo $form->field($this->model, $keyName, $inputOptions)
									->{$field->type}((array)$fieldOptions)
									->label($field->label);
							}
						}
					}
					
					echo Html::endTag('div');
					echo Html::endTag('div');
					echo Html::endTag('div');
				}
			}
			
			echo Html::endTag('div');
			echo Html::endTag('div');
			
			// handle save and save and return
			echo Html::hiddenInput('save_n_return', '0', ['id' => 'save_n_return']);
			
			\kartik\widgets\ActiveForm::end();
			
			return ob_get_clean();
		}
		
		public static function addError($error)
		{
			$err = '';
			if (\Yii::$app->session->hasFlash('globalError')) {
				$err .= \Yii::$app->session->getFlash('globalError') . "\n";
			}
			$err .= $error;
			\Yii::$app->session->setFlash('globalError', $err);
		}
		
		/**
		 * @throws NotFoundHttpException
		 */
		public function actionError()
		{
			throw new \yii\web\NotFoundHttpException('The requested action does not exist.');
		}
	}
