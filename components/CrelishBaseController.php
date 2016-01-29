<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 17:17
 */

namespace giantbits\crelish\components;

use yii;
use yii\base\Controller;

class CrelishBaseController extends Controller
{

    public $title;
    const SORT_ASC = 0;
    const SORT_DESC = 1;
    const SORT_NONE = 2;

    protected $locked = FALSE;
    protected $plugins;
    protected $requestUrl;
    protected $requestFile;
    protected $rawContent;
    protected $meta;
    protected $out;
    protected $pages;
    public $pageCollection;
    protected $page;
    protected $previousPage;
    protected $nextPage;
    protected $template;

    protected $fileHandler;
    protected $configHandler;

    public function init()
    {
        Yii::$app->language = Yii::$app->request->get('language');

        $this->configHandler = new CrelishConfig();
        $this->configHandler->loadConfig();

        $this->fileHandler = new CrelishFileHandler();
        $this->fileHandler->setConfig($this->configHandler);

        /* Workflow.

        1) Build page collection.
          - used for routing (specially explicit ordering [01., 02., etc])
          - used for navigation building (on page menus etc)
        */
        $this->pageCollection = $this->fileHandler->buildPageCollection();
        $this->resolvePathRequested();

        parent::init();

    }

    private function resolvePathRequested()
    {
        $page = null;
        $this->requestUrl = Yii::$app->request->get('pathRequested');

        if (!empty($this->requestUrl)) {

            $keys = explode('/', $this->requestUrl);
            foreach ($keys as $key) {
                if (empty($page)) {
                    $page = $this->pageCollection[$key];
                }
                if (isset($page[$key])) {
                    $page = $page[$key];
                } else {
                    $page = $page;
                }
            }
        }

        $this->page = $page;
    }

    public function actionRun()
    {

        $this->layout = 'main';

        if ($this->page) {
            $this->requestFile = $this->page['pathOrig'];
        } else {
            throw new \yii\web\NotFoundHttpException();
        }

        $this->rawContent = $this->fileHandler->loadFileContent($this->requestFile);

        $headers = $this->getMetaHeaders();
        $this->meta = $this->fileHandler->parseFileMeta($this->rawContent, $headers, $this->requestFile);

        // Build render output.
        $this->buildRenderOutput();

        // Switch layout if defined.
        if (!empty($this->meta['layout'])) {
            $this->layout = $this->meta['layout'];
        }

        // Switch template.
        $this->template = $this->fileHandler->selectTemplate($this->requestUrl, $this->meta);
        $this->title = $this->meta['title'];
        $this->view->title = $this->configHandler->config['site_title'] . ' ' . $this->title;

        $contentArray = explode("===", $this->out);

        if (count($contentArray) == 1) {
            $contentArray[1] = $contentArray[0];
            $contentArray[0] = '';
        }

        $pageData = array_merge([
            'summary' => $contentArray[0],
            'content' => $contentArray[1]
        ], $this->meta);

        // Render template.
        return $this->render($this->template, [
            'page' => $pageData
        ]);
    }

    protected function buildRenderOutput()
    {

        $type = (!empty($this->meta['type'])) ? $this->meta['type'] : NULL;

        //Check for processor class.
        //Run processor.
        //Run default processor.
        $processorClass = 'giantbits\crelish\plugin\core\\' . ucfirst($type) . 'TypeProcessor';

        if (class_exists($processorClass)) {

            $processor = new $processorClass($this->requestUrl, $this->requestFile, $this->meta, $this->rawContent, $this->fileHandler, $this->configHandler);
            $processor->fileHandler = $this->fileHandler;
            $processor->configHandler = $this->configHandler;
            $processedContent = $processor->getProcessorOutput();
            $this->out = $this->fileHandler->prepareFileContent($processedContent, $this->meta);

        } else {
            $this->out = $this->fileHandler->prepareFileContent($this->rawContent, $this->meta);
            $this->out = $this->fileHandler->parseFileContent($this->out);
        }
    }

    protected function readPages()
    {
        $this->pages = array();
        $files = $this->getFiles(Yii::$app->basePath . '/' . $this->configHandler->getConfig('content_dir'), $this->configHandler->getConfig('content_ext'));

        foreach ($files as $i => $file) {
            // skip 404 page
            if (basename($file) == '404' . $this->configHandler->getConfig('content_ext')) {
                unset($files[$i]);
                continue;
            }

            $id = substr($file, strlen($this->configHandler->getConfig('content_dir')), -strlen($this->configHandler->getConfig('content_ext')));

            // drop inaccessible pages (e.g. drop "sub.md" if "sub/index.md" exists)
            $conflictFile = $this->configHandler->getConfig('content_dir') . $id . '/index' . $this->configHandler->getConfig('content_ext');
            if (in_array($conflictFile, $files, TRUE)) {
                continue;
            }

            $url = $this->getPageUrl($id);
            if ($file != $this->requestFile) {
                $rawContent = file_get_contents($file);
                $meta = $this->fileHandler->parseFileMeta($rawContent, $this->getMetaHeaders());
            } else {
                $rawContent = &$this->rawContent;
                $meta = &$this->meta;
            }

            // build page data
            // title, description, author and date are assumed to be pretty basic data
            // everything else is accessible through $page['meta']
            $page = array(
                'id' => $id,
                'url' => $url,
                'title' => &$meta['title'],
                'description' => &$meta['description'],
                'author' => &$meta['author'],
                'time' => &$meta['time'],
                'date' => &$meta['date'],
                'date_formatted' => &$meta['date_formatted'],
                'raw_content' => &$rawContent,
                'meta' => &$meta
            );

            if ($file == $this->requestFile) {
                $page['content'] = &$this->out;
            }

            unset($rawContent, $meta);
            $this->pages[$id] = $page;
        }
    }

    public function getPageUrl($page)
    {
        return $page;
    }

    protected function getFiles($directory, $fileExtension = '', $order = self::SORT_ASC)
    {
        $directory = rtrim($directory, '/');
        $result = array();

        // Scandir() reads files in alphabetical order
        $files = scandir($directory, $order);
        $fileExtensionLength = strlen($fileExtension);
        if ($files !== FALSE) {
            foreach ($files as $file) {
                // exclude hidden files/dirs starting with a .; this also excludes the special dirs . and ..
                // exclude files ending with a ~ (vim/nano backup) or # (emacs backup)
                if ((substr($file, 0, 1) === '.') || in_array(substr($file, -1), array(
                        '~',
                        '#'
                    ))
                ) {
                    continue;
                }

                if (is_dir($directory . '/' . $file)) {
                    // get files recursively
                    $result = array_merge($result, $this->getFiles($directory . '/' . $file, $fileExtension, $order));
                } elseif (empty($fileExtension) || (substr($file, -$fileExtensionLength) === $fileExtension)) {
                    $result[] = $directory . '/' . $file;
                }
            }
        }

        return $result;
    }

    public function getRequestUrl()
    {
        return $this->requestUrl;
    }

    public function getRawContent()
    {
        return $this->rawContent;
    }

    public function getMetaHeaders()
    {
        $headers = array(
            'title' => 'Title',
            'description' => 'Description',
            'author' => 'Author',
            'date' => 'Date',
            'robots' => 'Robots',
            'template' => 'Template'
        );

        //$this->triggerEvent('onMetaHeaders', array(&$headers));
        return $headers;
    }

    public function getNav($level = 1)
    {
        $pages = [];

        if (empty(Yii::$app->controller->pageCollection)) {
            $pageCollection = [];
        } else {
            $pageCollection = Yii::$app->controller->pageCollection;
        }

        foreach ($pageCollection as $page) {
            if ($page['structureLevel'] > $level) {
                continue;
            }

            $pageData = Yii::$app->controller->fileHandler->loadFileContent($page['pathOrig']);
            $headers = Yii::$app->controller->getMetaHeaders();
            $pageData = array_merge_recursive($page, Yii::$app->controller->fileHandler->parseFileMeta($pageData, $headers, $page['pathOrig']));

            $pages[] = [
                'title' => (!empty($pageData['menu'])) ? $pageData['menu'] : $pageData['title'],
                'uri' => $pageData['self_url']
            ];
        }

        echo Yii::$app->view->render('nav.mustache', ['pages' => $pages]);
    }

    public function afterAction($action, $result)
    {
        $this->fileHandler->createStaticFile(\Yii::$app->request->getPathInfo(), $result);

        return parent::afterAction($action, $result);
    }
}
