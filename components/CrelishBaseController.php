<?php
namespace giantbits\crelish\components;

use giantbits\crelish\widgets\MatrixConnector;
use giantbits\crelish\widgets\AssetConnector;
use giantbits\crelish\widgets\DataList;
use giantbits\crelish\plugins;
use yii\bootstrap\ActiveForm;
use yii\web\Controller;
use yii\helpers\Json;
use yii\helpers\Html;
use yii\helpers\Url;

class CrelishBaseController extends Controller {
	protected $ctype, $uuid, $filePath, $elementDefinition, $model;

	protected function buildForm($action = 'update', $settings=array())
	{
		//default settings
		$defaults = array(
			'id'=>'content-form',
			'outerClass'=>'gc-bc--palette-clouds gc-bs--soft gc-ptb--2',
			'groupClass'=>'c-card',
			'tabs'=>[]
		);

		$settings = $settings + $defaults;

		// Build form for type.
		$this->model = new CrelishDynamicJsonModel([], ['ctype' => $this->ctype, 'uuid' => $this->uuid]);

		// Save content if post request.
		if (in_array($action, array('update','create')) && !empty(\Yii::$app->request->post()) && !\Yii::$app->request->isAjax) {
			$oldData = [];
			// Load old data.
			if (!empty($this->model->uuid)) {
				$oldData = Json::decode(file_get_contents(\Yii::getAlias('@app/workspace/data/').DIRECTORY_SEPARATOR.$this->ctype.DIRECTORY_SEPARATOR.$this->model->uuid.'.json'));
			}
			$attributes = $_POST['CrelishDynamicJsonModel'] + $oldData;
			foreach ($attributes as $key => $val) {
				foreach($this->model->fieldDefinitions->fields as $field) {
					if ($field->key == $key) {
						if (isset($field->transform)) {
							if (!isset($oldData[$key]) || $oldData[$key] != $attributes[$key]) {
								//we need to transform!
								$transformer = 'giantbits\\crelish\\components\\transformer\\CrelishFieldTransformer'.ucfirst(strtolower($field->transform));
								$transformer::transform($attributes[$key]);
							}
						}
						break;
					}
				}
			}
			$this->model->attributes = $attributes;

			if ($this->model->validate()) {
				$this->model->save();
				\Yii::$app->session->setFlash('success', 'Content saved successfully...');
				header('Location: '.Url::to(['content/update', 'ctype' => $this->ctype, 'uuid' => $this->model->uuid]));
				exit(0);
			} else {
				$errors = $this->model->errors;
			}
		}

		ob_start();
		$form = ActiveForm::begin([
			'id' => $settings['id'],
			//'layout' => 'horizontal',
		]);

		// Start output.
		echo Html::beginTag("div", ['class'=>$settings['outerClass']]);

		// Display messages.
		foreach (\Yii::$app->session->getAllFlashes() as $key => $message) {
			echo '<div class="c-alerts__alert c-alerts__alert--'.$key.'">'.$message.'</div>';
		}

		echo Html::beginTag("div", ['class'=>'o-grid']);

		// TODO: This has to be dynamicaly handled like it's done in frontend.
		//  Also the tabs and grouping mechanics have to be implemented.

		// Get the tabs (there has to be at least one).
		$tabs = $this->model->fieldDefinitions->tabs;

		//var_dump($tabs);
		foreach($tabs as $tab) {
			// Loop through tabs.
			//check tab overrides
			if (isset($settings['tabs'][$tab->key])) {
				foreach ($settings['tabs'][$tab->key] as $key => $val) {
					$tab->$key = $val;
				}
			}
			if (isset($tab->visible) && $tab->visible === false) {
				continue;
			}

			foreach($tab->groups as $group) {
				// Loop through groups.
				$widthClass = (!empty($group->settings->width)) ? 'o-grid__cell--width-' . $group->settings->width : '';

				echo Html::beginTag('div', ['class'=>'o-grid__cell ' . $widthClass]);
				echo Html::beginTag('div', ['class'=>$settings['groupClass']]);
				echo Html::tag('div', $group->label , ['class'=>'c-card__item c-card__item--divider']);
				echo Html::beginTag('div', ['class'=>'c-card__item']);

				foreach ($this->model->fieldDefinitions->fields as $field) {

					if(!in_array($field->key, $group->fields)) {
						continue;
					}

					// Build form fields.
					$fieldOptions = !empty($field->options) ? $field->options : [];

					if (strpos($field->type, 'widget_') !== false) {
						$widget = str_replace('widget_', '', $field->type);
						echo $form->field($this->model, $field->key)->widget($widget::className())->label($field->label);
					} elseif ($field->type == 'dropDownList') {
						echo $form->field($this->model, $field->key)->{$field->type}((array) $field->items, (array) $fieldOptions)->label($field->label);
					} elseif ($field->type == 'matrixConnector') {
						echo plugins\matrixconnector\MatrixConnector::widget(['formKey' => $field->key, 'data' => $this->model{$field->key},'field'=>$field]);
					} elseif ($field->type == 'assetConnector') {
						echo plugins\assetconnector\AssetConnector::widget(['formKey' => $field->key, 'data' => $this->model{$field->key},'field'=>$field]);
					} elseif ($field->type == 'dataList') {
						echo DataList::widget(['formKey' => $field->key, 'data' => $this->model{$field->key}]);
					} elseif ($field->type == 'submitButton') {
						echo Html::submitButton($field->label,array('class'=>'c-button c-button--brand c-button--block'));
					} else {
						echo $form->field($this->model, $field->key)->{$field->type}((array) $fieldOptions)->label($field->label);
					}
				}
				echo Html::endTag('div');
				echo Html::endTag('div');
				echo Html::endTag('div');
			}
		}

		echo Html::endTag('div');
		echo Html::endTag('div');

		ActiveForm::end();

		return ob_get_clean();
	}
}
