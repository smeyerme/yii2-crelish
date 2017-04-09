<?php
namespace giantbits\crelish\components;

use yii\bootstrap\ActiveForm;
use yii\web\Controller;
use yii\helpers\Json;
use yii\helpers\Html;
use yii\helpers\Url;

class CrelishBaseController extends Controller {
    protected $ctype, $uuid, $filePath, $elementDefinition, $model;

    public function init() {
        \Yii::$app->view->title = ucfirst($this->id);

        parent::init();
    }

    protected function buildForm($action = 'update', $settings = array()) {
        //default settings
        $defaults = array(
            'id' => 'content-form',
            'outerClass' => 'gc-ptb--2',
            'groupClass' => 'c-card',
            'tabs' => []
        );

        $settings = $settings + $defaults;

        // Build form for type.
        $this->model = new CrelishDynamicJsonModel([], [
            'ctype' => $this->ctype,
            'uuid' => $this->uuid
        ]);

        // Save content if post request.
        if (in_array($action, array(
                'update',
                'create'
            )) && !empty(\Yii::$app->request->post()) && !\Yii::$app->request->isAjax
        ) {
            $oldData = [];
            // Load old data.
            if (!empty($this->model->uuid)) {
                $oldData = Json::decode(file_get_contents(\Yii::getAlias('@app/workspace/data/') . DIRECTORY_SEPARATOR . $this->ctype . DIRECTORY_SEPARATOR . $this->model->uuid . '.json'));
            }
            $attributes = $_POST['CrelishDynamicJsonModel'] + $oldData;
            foreach ($attributes as $key => $val) {
                foreach ($this->model->fieldDefinitions->fields as $field) {
                    if ($field->key == $key) {
                        if (isset($field->transform)) {
                            if (!isset($oldData[$key]) || $oldData[$key] != $attributes[$key]) {
                                //we need to transform!
                                $transformer = 'giantbits\\crelish\\components\\transformer\\CrelishFieldTransformer' . ucfirst(strtolower($field->transform));
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
                header('Location: ' . Url::to([
                        'content/update',
                        'ctype' => $this->ctype,
                        'uuid' => $this->model->uuid
                    ]));
                exit(0);
            }
            else {
                $message = '';
                $errors = $this->model->errors;
                foreach ($errors as $error) {
                    $message .= $error[0];
                }
                \Yii::$app->session->setFlash('error', 'Error saving item: ' . $message);
            }
        }

        ob_start();
        $form = ActiveForm::begin([
            'id' => $settings['id'],
            //'layout' => 'horizontal',
        ]);

        // Start output.
        echo Html::beginTag("div", ['class' => $settings['outerClass']]);

        // Display messages.
        foreach (\Yii::$app->session->getAllFlashes() as $key => $message) {
            //echo '<div class="c-alerts__alert c-alerts__alert--'.$key.'">'.$message.'</div>';
        }

        echo Html::beginTag("div", ['class' => 'o-grid']);

        // TODO: This has to be dynamicaly handled like it's done in frontend.
        //  Also the tabs and grouping mechanics have to be implemented.

        // Get the tabs (there has to be at least one).
        $tabs = $this->model->fieldDefinitions->tabs;

        //var_dump($tabs);
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

                foreach ($this->model->fieldDefinitions->fields as $field) {

                    if (!in_array($field->key, $group->fields)) {
                        continue;
                    }

                    // Build form fields.
                    $fieldOptions = !empty($field->options) ? $field->options : [];

                    if (strpos($field->type, 'widget_') !== FALSE) {
                        $widget = str_replace('widget_', '', $field->type);
                        echo $form->field($this->model, $field->key)
                            ->widget($widget::className())
                            ->label($field->label);
                    }
                    elseif ($field->type == 'dropDownList') {
                        echo $form->field($this->model, $field->key)
                            ->{$field->type}((array) $field->items, (array) $fieldOptions)
                            ->label($field->label);
                    }
                    elseif ($field->type == 'submitButton') {
                        echo Html::submitButton($field->label, array('class' => 'c-button c-button--brand c-button--block'));
                    }
                    else {
                        $class = 'giantbits\crelish\plugins\\' . strtolower($field->type) . '\\' . ucfirst($field->type);
                        // Check for crelish special fields.
                        if (class_exists($class)) {
                            echo $class::widget([
                                'formKey' => $field->key,
                                'data' => $this->model{$field->key},
                                'field' => $field
                            ]);
                        }
                        else {
                            echo $form->field($this->model, $field->key)
                                ->{$field->type}((array) $fieldOptions)
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

        ActiveForm::end();

        return ob_get_clean();
    }

    public static function addError($error) {
        $err = '';
        if (\Yii::$app->session->hasFlash('globalError')) {
            $err .= \Yii::$app->session->getFlash('globalError') . "\n";
        }
        $err .= $error;
        \Yii::$app->session->setFlash('globalError', $err);
    }
}
