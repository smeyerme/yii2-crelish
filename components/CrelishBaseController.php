<?php

namespace giantbits\crelish\components;

use kartik\widgets\ActiveForm;
use Yii;
use yii\base\InvalidRouteException;
use yii\helpers\VarDumper;
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

  public function init()
  {

    parent::init();

    Yii::$app->sourceLanguage = 'en';
    Yii::$app->language = 'de';

    Yii::$app->view->title = ucfirst($this->id);

    if (!Yii::$app->user->isGuest) {
      $js = 'window.crelish = { "user": { "uuid": "' . Yii::$app->user->identity->uuid . '" }};';

      Yii::$app->view->registerJs($js, \yii\web\View::POS_HEAD);
    }

    //$intelliCache = Yii::$app->session->get('intellicache');
    /*if (!empty($intelliCache)) {

      $js = "$.ajax({
          url: '/crelish/settings/intellicache.html',
          data: {
              auth: '2e212e112e-2e12ea-vhrto4',
              uuid: '" . $this->uuid . "'
          },
          success: function(r){ console.info('Intellicache done'); }
      });";
      Yii::$app->view->registerJs($js);
      Yii::$app->session->remove('intellicache');
    }*/

    if ((Yii::$app->user->isGuest || Yii::$app->user->identity->role < 9)
      && Yii::$app->requestedRoute != 'crelish/user/login'
      && Yii::$app->requestedRoute != 'crelish/asset/glide') {
      return Yii::$app->response->redirect(['/']);
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
      'outerClass' => 'gc-ptb--2',
      'groupClass' => 'c-card',
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
    $html .= Html::beginTag("div", ['class' => 'o-grid o-grid--wrap o-grid--small-full']);
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
    $html .= Html::endTag('div');
    return $html;
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
    $widthClass = !empty($groupSettings->width) ? 'o-grid__cell--width-' . $groupSettings->width : '';

    $html = Html::beginTag('div', ['class' => 'o-grid__cell ' . $widthClass]);
    $html .= Html::beginTag('div', ['class' => $settings['groupClass']]);
    $html .= $this->renderGroupLabel($group, $groupSettings);
    $html .= Html::beginTag('div', ['class' => 'c-card__item']);
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
      return Html::tag('div', $group->label, ['class' => 'c-card__item c-card__item--brand']);
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
      return $this->buildFormField($field, $form);
    }
  }


  private function renderTranslatableField($field, $form): string
  {
    $html = '';
    if (count(Yii::$app->params['crelish']['languages']) > 1) {
      foreach (Yii::$app->params['crelish']['languages'] as $lang) {
        $html .= $this->buildFormField($field, $form, $lang);
      }
    }
    return $html;
  }

  private function endForm($form): void
  {
    ActiveForm::end();
  }

  private function buildFormField($field, $form, $lang = null)
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
      return $this->buildDefaultField($form, $field, $fieldKey, $inputOptions, $fieldOptions);
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
    $field->label .= ' (' . strtoupper($lang) . ')';
    if (!empty($this->model->allTranslations[$field->key])) {
      $currentValue = $this->model->allTranslations[$field->key][$lang] ?? $this->model->{$field->key};
      $fieldOptions['value'] = $currentValue;
      $widgetOptions['options']['value'] = $currentValue;
    }
  }

  private function buildWidgetField($form, $field, $fieldKey, $inputOptions, $widgetOptions)
  {
    $widget = str_replace('widget_', '', $field->type);
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

  private function buildDefaultField($form, $field, $fieldKey, $inputOptions, $fieldOptions)
  {
    $class = 'giantbits\crelish\plugins\\' . strtolower($field->type) . '\\' . ucfirst($field->type);
    if (class_exists($class)) {

      return $class::widget([
        'model' => $this->model,
        'formKey' => $fieldKey,
        'data' => $this->model[$fieldKey],
        'field' => $field
      ]);
    } else {
      return $form->field($this->model, $fieldKey, $inputOptions)
        ->{$field->type}((array)$fieldOptions)
        ->label($field->label);
    }
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
