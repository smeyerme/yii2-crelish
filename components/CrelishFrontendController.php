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
        $this->layout = 'main.twig';
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
        $this->data = new CrelishDynamicJsonModel([], ['ctype' => $this->entryPoint['ctype'], 'uuid' => $this->entryPoint['uuid']]);

        // Set layout.
        $this->setLayout();

        // Set view template.
        $this->setViewTemplate();

        // Process data and render.
        $data = CrelishBaseContentProcessor::processContent($this->entryPoint['ctype'], $this->data);

        if (isset(Yii::$app->params['crelish']['pageTitleAttribute']) && isset($data[Yii::$app->params['crelish']['pageTitleAttribute']])) {
            if (isset(Yii::$app->params['crelish']['pageTitle'])) {
                $this->view->title = str_replace('{title}',$data[Yii::$app->params['crelish']['pageTitleAttribute']],Yii::$app->params['crelish']['pageTitle']);

            } else {
                $this->view->title = $data[Yii::$app->params['crelish']['pageTitleAttribute']];
            }
        }

        return $this->render($this->viewTemplate, ['data' => $data]);
    }

    /**
     * [resolvePathRequested description]
     * @return [type] [description]
     */
    private function resolvePathRequested()
    {
        $slug = $path = \giantbits\crelish\Module::getInstance()->entryPoint['slug'];
        $ctype = \giantbits\crelish\Module::getInstance()->entryPoint['ctype'];
        $this->requestUrl = \Yii::$app->request->getPathInfo();

        if (!empty($params = \Yii::$app->request->getQueryParams())) {
            $slug = $params['pathRequested'];
        }

        $entryDataJoint = new CrelishJsonDataProvider($ctype, ['filter' => ['slug' => $slug]]);
        $entryModel = $entryDataJoint->one();

        if ($entryModel == null && isset(Yii::$app->params['crelish']['404slug'])) {
            Yii::$app->params['crelish']['404slug'] = 404;
            $entryDataJoint = new CrelishJsonDataProvider($ctype, ['filter' => ['slug' => Yii::$app->params['crelish']['404slug']]]);
            $entryModel = $entryDataJoint->one();
            $slug = $path = Yii::$app->params['crelish']['404slug'];
            Yii::$app->response->statusCode = 404;
        }

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
            $this->layout = '@app/themes/' . \giantbits\crelish\Module::getInstance()->theme . "/layouts/" . $this->entryPoint['slug'] . '.twig';
        } else {
            $this->layout = '@app/themes/' . \giantbits\crelish\Module::getInstance()->theme . "/layouts/main.twig";
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
        $pathByConfig = (!empty($this->data['template'])) ? \Yii::$app->view->theme->basePath . $ds . \Yii::$app->controller->id . $ds . $this->data['template'] : '';

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
