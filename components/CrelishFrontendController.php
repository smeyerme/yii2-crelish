<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 17:17
 */

namespace giantbits\crelish\components;

//use giantbits\crelish\components\CrelisJsonDataProvider;
use Yii;
use yii\base\Controller;
use Underscore\Types\Arrays;

class CrelishFrontendController extends Controller
{

  public $layout = "main.twig";

  /**
   * [$entryPoint description]
   * @var [type]
   */
  public $entryPoint;
  /**
   * [$requestUrl description]
   * @var [type]
   */
  private $requestUrl;
  /**
   * [$viewTemplate description]
   * @var [type]
   */
  private $viewTemplate;

  private $data;

  /**
   * [init description]
   * @return [type] [description]
   */
  public function init()
  {
    parent::init();

    // Set theme.
    // @todo: Move to config.
    $this->view->theme = new \yii\base\Theme([
      'pathMap' => ['@app/views' => '@app/themes/' . \giantbits\crelish\Module::getInstance()->theme],
      'basePath' => '@app/themes/' . \giantbits\crelish\Module::getInstance()->theme,
      'baseUrl' => '@web/themes/' . \giantbits\crelish\Module::getInstance()->theme,
    ]);

    // Force theming.
    $this->setViewPath('@app/themes/' . \giantbits\crelish\Module::getInstance()->theme . '/' . $this->id);

    // Define entry point.
    $this->resolvePathRequested();
  }

  /**
   * [actionError description]
   * @return [type] [description]
   */
  public function actionError()
  {
    $this->title = 'Error';
    \Yii::$app->name = $this->title;

    $exception = \Yii::$app->errorHandler->exception;

    if ($exception !== null) {
      return $this->render('error.twig', ['message' => $exception->getMessage()]);
    }
  }

  /**
   * [actionRun description]
   * @return [type] [description]
   */
  public function actionRun()
  {

    $ds = DIRECTORY_SEPARATOR;
    // 1. Determine entry point.
    // 2. Load entry point content.
    // 3. Assemble sub content from parent entry point content.

    // Add content aka. do the magic.
    $langDataFolder = (Yii::$app->params['defaultLanguage'] != Yii::$app->language) ? $ds . Yii::$app->language : '';
    $this->data = \yii\helpers\Json::decode(file_get_contents(\Yii::getAlias('@app/workspace/data') . $ds . $this->entryPoint['ctype'] . $langDataFolder . $ds . $this->entryPoint['uuid'] . '.json'));

    // Set layout.
    $this->setLayout();

    // Set view template.
    $this->setViewTemplate();

    // Process data and render.
    $data = $this->processContent($this->entryPoint['ctype'], $this->data);

    return $this->render($this->viewTemplate, ['data' => $data]);
  }

  /**
   * [processContent description]
   * @param  [type] $ctype [description]
   * @param  [type] $data  [description]
   * @return [type]        [description]
   */
  public function processContent($ctype, $data)
  {
    $processedData = [];

    $filePath = \Yii::getAlias('@app/workspace/elements') . DIRECTORY_SEPARATOR . $this->entryPoint['ctype'] . '.json';
    $definitionPath = \Yii::getAlias('@app/workspace/elements') . DIRECTORY_SEPARATOR . $ctype . '.json';

    $elementDefinition = CrelishDynamicJsonModel::loadElementDefinition($definitionPath);

    if ($data) {

      foreach ($data as $key => $content) {

        $fieldType = Arrays::find($elementDefinition->fields, function ($value) use ($key) {
          return $value->key == $key;
        });

        if (!empty($fieldType) && is_object($fieldType)) {
          $fieldType = $fieldType->type;
        }

        if(!empty($fieldType)) {
          // Get processor class.
          $processorClass = 'giantbits\crelish\plugins\\' . strtolower($fieldType) . '\\' . ucfirst($fieldType) . 'ContentProcessor';

          if(strpos($fieldType, "widget_") !== false) {
            $processorClass = str_replace("widget_", "", $fieldType) . 'ContentProcessor';
          }

          if (class_exists($processorClass)) {
            $processorClass::processData($this, $key, $content, $processedData);
          } else {
            $processedData[$key] = $content;
          }
        }
      }
    }
    return $processedData;
  }

  /**
   * [resolvePathRequested description]
   * @return [type] [description]
   */
  private function resolvePathRequested()
  {
    $slug = $path =  \giantbits\crelish\Module::getInstance()->entryPoint['slug'];
    $ctype = \giantbits\crelish\Module::getInstance()->entryPoint['ctype'];
    $this->requestUrl = \Yii::$app->request->getPathInfo();

    if(!empty($params = \Yii::$app->request->getQueryParams())) {
      $slug = $params['pathRequested'];
    }

    /*if (!empty($this->requestUrl)) {
      // Todo: Language handling.
      $keys = explode('/', $this->requestUrl);
      if (count($keys) > 1) {
        $path = $keys[0];
        $slug = str_replace(".html", "", $keys[1]);
      } else {
        $slug = str_replace(".html", "", $keys[0]);
      }
    }*/

    $entryDataJoint = new CrelishJsonDataProvider($ctype, ['filter' => ['slug' => $slug]]);
    $entryModel = $entryDataJoint->one();

    $this->entryPoint = ['ctype' => $ctype, 'slug' => $slug, 'path' => $path, 'uuid' => $entryModel['uuid']];
  }

  /**
   * [setLayout description]
   */
  private function setLayout()
  {

    $ds = DIRECTORY_SEPARATOR;
    $path = \Yii::$app->view->theme->basePath . $ds . 'layouts' . $ds . $this->entryPoint['slug'] . '.twig';

    if (file_exists($path)) {
      $this->layout = "@app/views/layouts/" . $this->entryPoint['slug'] . ".twig";
    } else {
      $this->layout = "@app/views/layouts/main.twig";
    }
  }

  /**
   * [setViewTemplate description]
   */
  private function setViewTemplate()
  {
    $ds = DIRECTORY_SEPARATOR;
    $path = \Yii::$app->view->theme->basePath . $ds . \Yii::$app->controller->id . $ds . $this->entryPoint['slug'] . '.twig';
    $pathByType = \Yii::$app->view->theme->basePath . $ds . \Yii::$app->controller->id . $ds . $this->entryPoint['ctype'] . '.twig';
    $pathByConfig = \Yii::$app->view->theme->basePath . $ds . \Yii::$app->controller->id . $ds .  $this->data['template'];

    if (file_exists($path)) {
      $this->viewTemplate = $this->entryPoint['slug'] . '.twig';
    } elseif (file_exists($pathByType)) {
      $this->viewTemplate = $this->entryPoint['ctype'] . '.twig';
    } elseif (file_exists($pathByConfig)) {
      $this->viewTemplate = $this->data['template'];
    } else {
      $this->viewTemplate = 'main.twig';
    }
  }
}
