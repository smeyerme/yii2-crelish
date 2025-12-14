<?php

namespace giantbits\crelish\components;

use kartik\widgets\ActiveForm;
use Yii;
use yii\base\InvalidRouteException;
use yii\i18n\Formatter;
use yii\web\Controller;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;

class CrelishBaseController extends Controller
{
  protected $uuid, $filePath, $elementDefinition;
  public $ctype;
  public $model;
  public $nonce;
  public $layout = 'crelish.twig';

  public function init()
  {

    parent::init();

    Yii::$app->view->title = ucfirst($this->id);
    
    // Handle common session and query parameters
    $this->handleSessionAndQueryParams('cr_content_filter');
    $this->handleSessionAndQueryParams('cr_status_filter');
    $this->handleSessionAndQueryParams('ctype');

    if (!Yii::$app->user->isGuest) {
      $js = 'window.crelish = { "user": { "uuid": "' . Yii::$app->user->identity->uuid . '" }};';

      Yii::$app->view->registerJs($js, \yii\web\View::POS_HEAD);
    }

    if ((Yii::$app->user->isGuest || Yii::$app->user->identity->role < 9)
      && Yii::$app->requestedRoute != 'crelish/user/login'
      && Yii::$app->requestedRoute != 'crelish/asset/glide'
      && !str_starts_with(Yii::$app->requestedRoute, 'crelish/asset/api-')) {
      return Yii::$app->response->redirect(['/']);
    }
  }

  public function behaviors()
  {
    return [
      'botDetection' => [
        'class' => 'giantbits\crelish\components\BotDetectionMiddleware'
      ]
    ];
  }

  /**
   * Called before the action is executed
   * 
   * @param \yii\base\Action $action the action to be executed
   * @return bool whether the action should continue to be executed
   */
  public function beforeAction($action)
  {
    if (!parent::beforeAction($action)) {
      return false;
    }
    
    // Set default header bar components based on the current action
    $this->setupHeaderBar();
    
    return true;
  }

  /**
   * Handle session and query parameters
   * 
   * @param string $paramName The parameter name to handle
   * @return mixed The parameter value or null
   */
  protected function handleSessionAndQueryParams($paramName)
  {
    $value = null;
    
    if (isset($_GET[$paramName])) {
      Yii::$app->session->set($paramName, $_GET[$paramName]);
      $value = $_GET[$paramName];
    } elseif (Yii::$app->session->get($paramName) !== null) {
      $_GET[$paramName] = Yii::$app->session->get($paramName);
      $value = Yii::$app->session->get($paramName);
    }
    
    return $value;
  }

  /**
   * Set up the header bar components based on the current action
   */
  protected function setupHeaderBar()
  {
    // Default left components for all actions
    $this->view->params['headerBarLeft'] = ['toggle-sidebar'];
    
    // Default right components (empty by default)
    $this->view->params['headerBarRight'] = [];
    
    // Set specific components based on action
    $action = $this->action ? $this->action->id : null;
    
    switch ($action) {
      case 'index':
        // For index actions, add search and create/delete buttons
        $this->view->params['headerBarLeft'][] = 'search';
        $this->view->params['headerBarRight'] = ['delete', 'create'];
        break;
        
      case 'create':
        // For create actions, add back button and save buttons (without delete)
        $this->view->params['headerBarLeft'][] = 'back-button';
        $this->view->params['headerBarRight'] = [['save', true, false]]; // Show save and return, no delete
        break;
        
      case 'update':
        // For update actions, add back button and save buttons (with delete)
        $this->view->params['headerBarLeft'][] = 'back-button';
        $this->view->params['headerBarRight'] = [['save', true, true]]; // Show save and return, with delete
        break;
        
      default:
        // For other actions, just keep the defaults
        break;
    }
  }

  public function actions(): array
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

  public function buildForm($action = 'default', $settings = []): bool|string
  {

    $this->initializeFormSettings($settings);
    $this->initializeModel($action, $settings);

    if ($this->isPostRequest()) {
      $this->handleFormSubmission();
    }

    $this->formatModelFields();

    ob_start();
    $form = $this->beginForm($settings);

    echo $this->renderFormStructure($form, $settings);

    $this->endForm($form);
    return ob_get_clean();
  }

  private function initializeFormSettings(&$settings): void
  {
    $defaults = [
      'id' => 'content-form',
      'outerClass' => null,
      'groupClass' => 'card',
      'tabs' => []
    ];
    $settings = array_merge($defaults, $settings);
  }

  private function initializeModel($action, $settings): void
  {
    $this->model = new CrelishDynamicModel( [
      'ctype' => $settings['ctype'] ?? $this->ctype,
      'uuid' => $settings['uuid'] ?? $this->uuid
    ]);

    if ($action !== 'default') {
      $this->model->scenario = $action;
    }
  }

  private function isPostRequest(): bool
  {
    return !empty(Yii::$app->request->post()) && !Yii::$app->request->isAjax;
  }

  private function handleFormSubmission(): void
  {
    $oldData = $this->model->uuid ? $this->model->attributes : [];
    $attributes = $_POST['CrelishDynamicModel'] + $oldData;
    $this->model->attributes = $attributes;

    if ($this->model->validate() && $this->model->save()) {
      $this->handleSuccessfulSave();
    } else {
      $this->handleSaveError();
    }
  }

  private function handleSuccessfulSave(): void
  {
    Yii::$app->session->setFlash('success', Yii::t("app", 'Content saved successfully...'));

    $redirectUrl = !empty($_POST['save_n_return']) && $_POST['save_n_return'] == "1"
      ? [Yii::$app->controller->id . '/index', 'ctype' => $this->ctype]
      : [Yii::$app->controller->id . '/update', 'ctype' => $this->ctype, 'uuid' => $this->model->uuid];

    Yii::$app->response->redirect(Url::to($redirectUrl))->send();
    Yii::$app->end();
  }

  private function handleSaveError(): void
  {
    $errors = $this->model->errors;
    $message = implode(', ', array_map(function ($error) {
      return $error[0];
    }, $errors));
    Yii::$app->session->setFlash('error', Yii::t('app', 'Error saving item: ') . $message);
  }

  private function formatModelFields(): void
  {
    $formatter = new Formatter();
    $formatter->dateFormat = "dd.MM.yyyy";
    $formatter->nullDisplay = "";

    foreach ($this->model->fieldDefinitions->fields as $field) {
      $this->formatField($field, $formatter);
    }
  }

  private function formatField($field, $formatter): void
  {
    if (!empty($field->format)) {
      if ($field->format == 'date') {
        $this->model->{$field->key} = $formatter->asDate($this->model->{$field->key});
      } elseif ($field->format == 'datetime') {
        $this->model->{$field->key} = $formatter->asDatetime($this->model->{$field->key}, 'dd.MM.yyyy HH:mm');
      }
    }

    if (!empty($field->defaultValue) && empty($this->uuid)) {
      $this->model->{$field->key} = $field->defaultValue;
    }
  }

  private function beginForm($settings): \yii\base\Widget|ActiveForm
  {
    return ActiveForm::begin([
      'id' => $settings['id'],
      'options' => [
        'role' => 'presentation',
        'autocomplete' => 'off'
      ]
    ]);
  }

  private function renderFormStructure($form, $settings): string
  {

    $html = Html::beginTag("div", ['class' => $settings['outerClass']]);
    $html .= $this->renderLanguageSelector();
    $html .= Html::beginTag("div", ['class' => 'row']);
    $html .= $this->renderTabs($form, $settings);
    $html .= Html::endTag('div');
    $html .= Html::endTag('div');
    $html .= Html::hiddenInput('save_n_return', '0', ['id' => 'save_n_return']);
    return $html;
  }

  private function renderLanguageSelector(): string
  {
    if (count(Yii::$app->params['crelish']['languages']) <= 1) {
      return '';
    }

    $html = Html::beginTag('div', ['class' => 'lang-ui-switch']);
    $html .= '<span>' . Yii::t('app', 'Select language to edit:') . '</span><select id="language-select">';
    foreach (Yii::$app->params['crelish']['languages'] as $lang) {
      $html .= Html::tag('option', strtoupper($lang), [
        'value' => $lang,
        'selected' => ($lang == Yii::$app->language)
      ]);
    }
    $html .= '</select>';

    // Add auto-translate button if translation service is available
    if (CrelishTranslationService::isAvailable()) {
      $sourceLanguage = Yii::$app->sourceLanguage ?? 'de';
      $ctype = $this->ctype ?? '';
      $uuid = $this->uuid ?? '';

      $html .= Html::button(
        '<i class="fa fa-language"></i> ' . Yii::t('app', 'Auto-translate'),
        [
          'id' => 'auto-translate-btn',
          'class' => 'btn btn-sm btn-outline-primary ms-2',
          'data-source-language' => $sourceLanguage,
          'data-ctype' => $ctype,
          'data-uuid' => $uuid,
          'style' => 'display: none;', // Hidden by default, shown via JS when non-source language selected
          'title' => Yii::t('app', 'Translate all fields from {source} to selected language', ['source' => strtoupper($sourceLanguage)])
        ]
      );

      // Register the translation JavaScript
      $this->registerTranslationScript($sourceLanguage);
    }

    $html .= Html::endTag('div');
    return $html;
  }

  /**
   * Register JavaScript for handling auto-translation
   */
  private function registerTranslationScript(string $sourceLanguage): void
  {
    $translateUrl = Url::to(['/crelish/translation/translate']);
    $loadingText = Yii::t('app', 'Translating...');
    $errorText = Yii::t('app', 'Translation failed. Please try again.');
    $successText = Yii::t('app', 'Fields translated successfully!');

    $js = <<<JS
(function() {
    var sourceLanguage = '{$sourceLanguage}';
    var translateBtn = document.getElementById('auto-translate-btn');
    var languageSelect = document.getElementById('language-select');

    if (!translateBtn || !languageSelect) return;

    // Show/hide translate button based on selected language
    function updateTranslateButtonVisibility() {
        var selectedLang = languageSelect.value;
        if (selectedLang !== sourceLanguage && !selectedLang.startsWith(sourceLanguage)) {
            translateBtn.style.display = 'inline-block';
        } else {
            translateBtn.style.display = 'none';
        }
    }

    // Set value to a target field, handling WYSIWYG editors
    function setFieldValue(fieldKey, targetLanguage, value) {
        var targetSelector = '[name*="[' + targetLanguage + '][' + fieldKey + ']"]';
        var targetInput = document.querySelector(targetSelector);

        if (!targetInput) return false;

        var finalValue = typeof value === 'object' ? JSON.stringify(value) : value;

        // Set the textarea/input value
        targetInput.value = finalValue;

        // Handle Trumbowyg WYSIWYG editor
        if (typeof jQuery !== 'undefined' && jQuery.fn.trumbowyg) {
            var jqTarget = jQuery(targetInput);
            if (jqTarget.data('trumbowyg')) {
                jqTarget.trumbowyg('html', finalValue);
            }
        }

        // Handle CKEditor
        if (typeof CKEDITOR !== 'undefined' && CKEDITOR.instances[targetInput.id]) {
            CKEDITOR.instances[targetInput.id].setData(finalValue);
        }

        // Handle TinyMCE
        if (typeof tinymce !== 'undefined') {
            var editor = tinymce.get(targetInput.id);
            if (editor) {
                editor.setContent(finalValue);
            }
        }

        // Trigger change event for any listeners
        targetInput.dispatchEvent(new Event('change', { bubbles: true }));
        targetInput.dispatchEvent(new Event('input', { bubbles: true }));

        return true;
    }

    // Initial visibility check
    updateTranslateButtonVisibility();

    // Update visibility when language changes
    languageSelect.addEventListener('change', updateTranslateButtonVisibility);

    // Handle translate button click
    translateBtn.addEventListener('click', function(e) {
        e.preventDefault();

        var targetLanguage = languageSelect.value;
        var ctype = translateBtn.dataset.ctype;
        var uuid = translateBtn.dataset.uuid;

        if (!ctype) {
            alert('Content type not available');
            return;
        }

        if (!uuid) {
            alert('Please save the content first before translating.');
            return;
        }

        // Show loading state
        var originalText = translateBtn.innerHTML;
        translateBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> {$loadingText}';
        translateBtn.disabled = true;

        // Send translation request - server loads source data from stored model
        fetch('{$translateUrl}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                ctype: ctype,
                uuid: uuid,
                targetLanguage: targetLanguage
            })
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            translateBtn.innerHTML = originalText;
            translateBtn.disabled = false;

            if (data.success && data.translations) {
                var fieldsPopulated = 0;

                // Populate translated values into target language fields
                Object.keys(data.translations).forEach(function(fieldKey) {
                    if (setFieldValue(fieldKey, targetLanguage, data.translations[fieldKey])) {
                        fieldsPopulated++;
                    }
                });

                // Show success message
                var flashContainer = document.querySelector('.flash-messages') || document.querySelector('.container-fluid');
                if (flashContainer) {
                    var alert = document.createElement('div');
                    alert.className = 'alert alert-success alert-dismissible fade show';
                    alert.innerHTML = '{$successText} (' + fieldsPopulated + ' fields) <button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                    flashContainer.insertBefore(alert, flashContainer.firstChild);
                    setTimeout(function() { alert.remove(); }, 5000);
                }
            } else {
                alert(data.error || '{$errorText}');
            }
        })
        .catch(function(error) {
            translateBtn.innerHTML = originalText;
            translateBtn.disabled = false;
            console.error('Translation error:', error);
            alert('{$errorText}');
        });
    });
})();
JS;

    Yii::$app->view->registerJs($js, \yii\web\View::POS_END);
  }

  private function renderTabs($form, $settings): string
  {
    $html = '';

    foreach ($this->model->fieldDefinitions->tabs as $tab) {

      if ($this->isTabVisible($tab)) {
        $html .= $this->renderTab($tab, $form, $settings);
      }
    }
    return $html;
  }

  private function isTabVisible($tab): bool
  {
    return !isset($tab->visible) || $tab->visible !== false;
  }

  private function renderTab($tab, $form, $settings): string
  {

    $html = '';
    foreach ($tab->groups as $group) {
      $html .= $this->renderGroup($group, $form, $settings);
    }
    return $html;
  }

  private function renderGroup($group, $form, $settings): string
  {
    $groupSettings = property_exists($group, 'settings') ? $group->settings : [];

    $widthClass = !empty($groupSettings->width) ? 'col-md-' . $groupSettings->width : '';

    $html = Html::beginTag('div', ['class' => 'col ' . $widthClass]);
    $html .= Html::beginTag('div', ['class' => $settings['groupClass']]);
    $html .= $this->renderGroupLabel($group, $groupSettings);
    $html .= Html::beginTag('div', ['class' => 'card-body']);
    $html .= $this->renderGroupFields($group, $form);
    $html .= Html::endTag('div');
    $html .= Html::endTag('div');
    $html .= Html::endTag('div');

    return $html;
  }

  private function renderGroupLabel($group, $groupSettings): string
  {
    if (empty($groupSettings) ||
      (property_exists($groupSettings, 'showLabel') && $groupSettings->showLabel !== false) ||
      !property_exists($groupSettings, 'showLabel')) {
      return Html::tag('div', $group->label, ['class' => 'card-header']);
    }
    return '';
  }

  private function renderGroupFields($group, $form): string
  {
    $html = '';
    foreach ($this->model->fieldDefinitions->fields as $field) {
      if (in_array($field->key, $group->fields)) {
        $html .= $this->renderField($field, $form);
      }
    }
    return $html;
  }

  private function renderField($field, $form)
  {
    if (property_exists($field, 'translatable') && $field->translatable === true) {
      return $this->renderTranslatableField($field, $form);
    } else {
      return $this->buildCustomOrDefaultField($field, $form);
    }
  }

  private function renderTranslatableField($field, $form): string
  {
    $html = '';
    if (count(Yii::$app->params['crelish']['languages']) > 1) {
      foreach (Yii::$app->params['crelish']['languages'] as $lang) {
        $html .= $this->buildCustomOrDefaultField($field, $form, $lang);
      }
    }
    return $html;
  }

  private function endForm($form): void
  {
    ActiveForm::end();
  }

  private function buildCustomOrDefaultField($field, $form, $lang = null)
  {
    $field->type = $field->type ?? "textInput";
    $fieldKey = $this->getFieldKey($field, $lang);
    $fieldOptions = $this->getFieldOptions($field);
    $widgetOptions = $this->getWidgetOptions($field);
    $inputOptions = $this->getInputOptions($field, $lang);

    if ($this->isTranslation($lang)) {
      $this->handleTranslationOptions($field, $fieldOptions, $widgetOptions, $lang);
    }

    if (str_contains($field->type, 'widget_')) {
      return $this->buildWidgetField($form, $field, $fieldKey, $inputOptions, $widgetOptions);
    } elseif (in_array($field->type, ['dropDownList', 'checkboxList'])) {
      return $this->buildListField($form, $field, $fieldKey, $fieldOptions);
    } elseif ($field->type == 'submitButton') {
      return $this->buildSubmitButton($field);
    } elseif ($field->type == 'passwordInput') {
      return $this->buildPasswordField($form, $field, $fieldKey, $inputOptions, $fieldOptions);
    } else {
      $class = 'giantbits\crelish\plugins\\' . strtolower($field->type) . '\\' . ucfirst($field->type);
      if (class_exists($class)) {
        // Get field data safely - prevent accessing undefined array indices
        $fieldData = null;
        
        // For translatable fields, try to get from i18n array
        if (property_exists($field, 'translatable') && $field->translatable === true) {
          $currentLang = $lang ?: Yii::$app->language;
          
          // Make sure i18n is initialized
          if (!isset($this->model->i18n) || !is_array($this->model->i18n)) {
            $this->model->i18n = [];
          }
          
          // Make sure language array exists
          if (!isset($this->model->i18n[$currentLang])) {
            $this->model->i18n[$currentLang] = [];
          }
          
          // Get field value if it exists, otherwise null
          if (isset($this->model->i18n[$currentLang][$field->key])) {
            $fieldData = $this->model->i18n[$currentLang][$field->key];
          } elseif (isset($this->model->{$field->key})) {
            // Fallback to non-translated field
            $fieldData = $this->model->{$field->key};
          }
        } else {
          // Try direct property access first
          if (isset($this->model->{$field->key})) {
            $fieldData = $this->model->{$field->key};
          } elseif (isset($this->model[$fieldKey])) {
            // Then try array access
            $fieldData = $this->model[$fieldKey];
          }
        }

        return $class::widget([
          'model' => $this->model,
          'formKey' => $fieldKey,
          'data' => $fieldData,
          'field' => $field
        ]);
      } else {
        return $form->field($this->model, $fieldKey, $inputOptions)
          ->{$field->type}((array)$fieldOptions)
          ->label($field->label);
      }
    }
  }

  private function getFieldKey($field, $lang): string
  {
    $isTranslation = $this->isTranslation($lang);
    return $isTranslation ? "i18n[{$lang}][{$field->key}]" : $field->key;
  }

  private function getFieldOptions($field): array
  {
    if (!isset($field->options)) {
      return [];
    }

    if (is_array($field->options)) {
      return $field->options;
    }

    if (is_object($field->options)) {
      return (array)$field->options;
    }

    return [];
  }

  private function getWidgetOptions($field): array
  {
    if (!isset($field->widgetOptions)) {
      return [];
    }

    if (is_array($field->widgetOptions)) {
      return $field->widgetOptions;
    }

    if (is_object($field->widgetOptions)) {
      return (array)$field->widgetOptions;
    }

    return [];
  }

  private function getInputOptions($field, $lang): array
  {
    $inputOptions = isset($field->inputOptions) ? (array)$field->inputOptions : [];
    if (!empty($lang)) {
      $inputOptions['options'] = $inputOptions['options'] ?? [];
      $inputOptions['options']['data-language'] = $lang;
      if ($this->isTranslation($lang)) {
        $inputOptions['options']['class'] = ($inputOptions['options']['class'] ?? '') . ' lang-ver';
      }
    }
    return $inputOptions;
  }

  private function isTranslation($lang): bool
  {
    return !empty($lang) && $lang != Yii::$app->language;
  }

  private function handleTranslationOptions(&$field, &$fieldOptions, &$widgetOptions, $lang): void
  {

    if (!empty($this->model->allTranslations[$field->key])) {
      $currentValue = $this->model->allTranslations[$field->key][$lang] ?? $this->model->{$field->key};
      $fieldOptions['value'] = $currentValue;
      $widgetOptions['options']['value'] = $currentValue;
    }
  }

  private function buildWidgetField($form, $field, $fieldKey, $inputOptions, $widgetOptions)
  {
    $widget = str_replace('widget_', '', $field->type);

    // Add field definition and form key to widget options only for custom Crelish widgets
    if (str_contains($widget, 'giantbits\\crelish\\')) {
      $widgetOptions['field'] = $field;
      $widgetOptions['formKey'] = $fieldKey;

      // Get field data for custom Crelish widgets (same logic as buildCustomOrDefaultField)
      $fieldData = null;

      // For translatable fields, try to get from i18n array
      if (property_exists($field, 'translatable') && $field->translatable === true) {
        $currentLang = Yii::$app->language;

        // Make sure i18n is initialized
        if (!isset($this->model->i18n) || !is_array($this->model->i18n)) {
          $this->model->i18n = [];
        }

        // Make sure language array exists
        if (!isset($this->model->i18n[$currentLang])) {
          $this->model->i18n[$currentLang] = [];
        }

        // Get field value if it exists, otherwise null
        if (isset($this->model->i18n[$currentLang][$field->key])) {
          $fieldData = $this->model->i18n[$currentLang][$field->key];
        } elseif (isset($this->model->{$field->key})) {
          // Fallback to non-translated field
          $fieldData = $this->model->{$field->key};
        }
      } else {
        // Try direct property access first
        if (isset($this->model->{$field->key})) {
          $fieldData = $this->model->{$field->key};
        } elseif (isset($this->model->attributes[$field->key])) {
          // Then try attributes array access
          $fieldData = $this->model->attributes[$field->key];
        }
      }

      $widgetOptions['data'] = $fieldData;
    }

    return $form->field($this->model, $fieldKey, $inputOptions)
      ->widget($widget::className(), $widgetOptions)
      ->label($field->label);
  }

  private function buildListField($form, $field, $fieldKey, $fieldOptions)
  {
    return $form->field($this->model, $fieldKey)
      ->{$field->type}((array)$field->items, (array)$fieldOptions)
      ->label($field->label);
  }

  private function buildSubmitButton($field): string
  {
    return Html::submitButton($field->label, ['class' => 'c-button c-button--brand c-button--block']);
  }

  private function buildPasswordField($form, $field, $fieldKey, $inputOptions, $fieldOptions)
  {
    unset($this->model[$fieldKey]);
    return $form->field($this->model, $fieldKey, $inputOptions)
      ->{$field->type}((array)$fieldOptions)
      ->label($field->label);
  }

  public static function addError($error): void
  {
    $err = '';
    if (Yii::$app->session->hasFlash('globalError')) {
      $err .= Yii::$app->session->getFlash('globalError') . "\n";
    }
    $err .= $error;
    Yii::$app->session->setFlash('globalError', $err);
  }

  /**
   * @throws NotFoundHttpException
   */
  public function actionError()
  {
    throw new NotFoundHttpException('The requested action does not exist.');
  }
}
