<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 29.11.15
 * Time: 17:17
 */

namespace app\components;

use yii;
use yii\base\Controller;

class CrelishBaseController extends Controller {

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
  protected $currentPage;
  protected $previousPage;
  protected $nextPage;
  protected $template;

  protected $fileHandler;
  protected $configHandler;

  public function init() {
    parent::init();

    $this->configHandler = new CrelishConfig();
    $this->configHandler->loadConfig();

    $this->fileHandler = new CrelishFileHandler();
    $this->fileHandler->setConfig($this->configHandler);
  }

  public function actionRun() {
    $this->layout = 'main';
    $this->requestUrl = Yii::$app->request->get('pathRequested');

    // Discover requested file.
    $this->discoverRequestFile();

    if (file_exists($this->requestFile)) {
      $this->rawContent = $this->loadFileContent($this->requestFile);
    }
    else {
      header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
      $this->rawContent = $this->load404Content($this->requestFile);
    }

    $headers = $this->getMetaHeaders();
    $this->meta = $this->fileHandler->parseFileMeta($this->rawContent, $headers);

    // Build render output.
    $this->buildRenderOutput();

    $this->readPages();
    $this->sortPages();
    $this->discoverCurrentPage();

    // Switch layout if defined.
    if (!empty($this->meta['layout'])) {
      $this->layout = $this->meta['layout'];
    }

    // Switch template.
    $this->template = $this->selectTemplate($this->meta);

    $this->title = $this->meta['title'];


    // Render template.
    return $this->render($this->template, ['content' => $this->out, 'data'=>$this->meta]);
  }

  protected function processModularFile($file) {
    $rawContent = $this->loadFileContent($file);

    //$headers = $this->getMetaHeaders();
    $meta = $this->fileHandler->parseFileMeta($rawContent);

    $processedContent = $this->fileHandler->prepareFileContent($rawContent, $meta, true);
    $processedContent = $this->fileHandler->parseFileContent($processedContent);

    $template = $this->selectTemplate($meta, $file);

    return $this->renderPartial($template, ['content' => $processedContent, 'data'=>$meta]);
  }

  protected function buildRenderOutput() {

    $type = (!empty($this->meta['type'])) ? $this->meta['type'] : NULL;

    switch ($type) {
      case 'modular':


        break;

      default:
        $this->out = $this->fileHandler->prepareFileContent($this->rawContent, $this->meta);
        $this->out = $this->fileHandler->parseFileContent($this->out);
    }

  }

  protected function selectTemplate($meta, $file = NULL) {
    $template = (!empty($file) ? 'modular/' : '') . 'default.mustache';

    $requestTemplate = $this->requestUrl;

    if(!empty($file)) {
      $pathArr = explode("/", $file);
      $segment = count($pathArr) - 2;
      $requestTemplate = preg_replace('/[0-9]+/', '', $pathArr[$segment]);
    }

    // Check if there is a template/view by the name of the requested path.
    if (file_exists(Yii::$app->view->theme->basePath . '/frontend/' . (!empty($file) ? 'modular/' : '') . $requestTemplate)) {
      $template = (!empty($file) ? 'modular/' : '') . $requestTemplate;
    }

    if (file_exists(Yii::$app->view->theme->basePath . '/frontend/'  . (!empty($file) ? 'modular/' : '') . $requestTemplate . '.mustache')) {
      $template = (!empty($file) ? 'modular/' : '') . $requestTemplate . '.mustache';
    }

    // Manually defined templates have highest priority.
    if (!empty($meta['template'])) {
      $template = $meta['template'];
    }

    return $template;

  }

  protected function readPages() {
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
      }
      else {
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

  public function getPageUrl($page) {
    return $page;
  }

  protected function getFiles($directory, $fileExtension = '', $order = self::SORT_ASC) {
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
        }
        elseif (empty($fileExtension) || (substr($file, -$fileExtensionLength) === $fileExtension)) {
          $result[] = $directory . '/' . $file;
        }
      }
    }

    return $result;
  }

  public function getRequestUrl() {
    return $this->requestUrl;
  }

  protected function discoverRequestFile() {
    if (empty($this->requestUrl)) {
      $this->requestFile = $this->configHandler->getConfig('content_dir') . 'index' . $this->configHandler->getConfig('content_ext');
    }
    else {
      // prevent content_dir breakouts using malicious request URLs
      // we don't use realpath() here because we neither want to check for file existance
      // nor prohibit symlinks which intentionally point to somewhere outside the content_dir
      // it is STRONGLY RECOMMENDED to use open_basedir
      $requestUrl = str_replace('\\', '/', $this->requestUrl);
      $requestUrlParts = explode('/', $requestUrl);

      $requestFileParts = array();
      foreach ($requestUrlParts as $requestUrlPart) {
        if (($requestUrlPart === '') || ($requestUrlPart === '.')) {
          continue;
        }
        elseif ($requestUrlPart === '..') {
          array_pop($requestFileParts);
          continue;
        }

        $requestFileParts[] = $requestUrlPart;
      }

      if (empty($requestFileParts)) {
        $this->requestFile = $this->configHandler->getConfig('content_dir') . 'index' . $this->configHandler->getConfig('content_ext');
        return;
      }

      // discover the content file to serve
      // Note: $requestFileParts neither contains a trailing nor a leading slash
      $this->requestFile = Yii::$app->basePath . '/' . $this->configHandler->getConfig('content_dir') . implode('/', $requestFileParts);

      if (is_dir($this->requestFile)) {
        // if no index file is found, try a accordingly named file in the previous dir
        // if this file doesn't exist either, show the 404 page, but assume the index
        $indexFile = $this->requestFile . '/index' . $this->configHandler->getConfig('content_ext');
        if (file_exists($indexFile) || !file_exists($this->requestFile . $this->configHandler->getConfig('content_ext'))) {
          $this->requestFile = $indexFile;
          return;
        }
      }
      $this->requestFile .= $this->configHandler->getConfig('content_ext');
    }
  }

  public function loadFileContent($file) {
    return file_get_contents($file);
  }

  public function load404Content($file) {
    $errorFileDir = substr($file, strlen($this->configHandler->getConfig('content_dir')));
    do {
      $errorFileDir = dirname($errorFileDir);
      $errorFile = $errorFileDir . '/404' . $this->configHandler->getConfig('content_ext');
    } while (!file_exists($this->configHandler->getConfig('content_dir') . $errorFile) && ($errorFileDir !== '.'));

    if (!file_exists($this->configHandler->getConfig('content_dir') . $errorFile)) {
      $errorFile = ($errorFileDir === '.') ? '404' . $this->configHandler->getConfig('content_ext') : $errorFile;
      throw new RuntimeException('Required "' . $errorFile . '" not found');
    }

    return $this->loadFileContent($this->configHandler->getConfig('content_dir') . $errorFile);
  }

  public function getRawContent() {
    return $this->rawContent;
  }

  public function getMetaHeaders() {
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

  protected function sortPages() {
    // sort pages
    $order = $this->configHandler->getConfig('pages_order');
    $alphaSortClosure = function ($a, $b) use ($order) {
      $aSortKey = (basename($a['id']) === 'index') ? dirname($a['id']) : $a['id'];
      $bSortKey = (basename($b['id']) === 'index') ? dirname($b['id']) : $b['id'];

      $cmp = strcmp($aSortKey, $bSortKey);
      return $cmp * (($order == 'desc') ? -1 : 1);
    };

    if ($this->configHandler->getConfig('pages_order_by') == 'date') {
      // sort by date
      uasort($this->pages, function ($a, $b) use ($alphaSortClosure, $order) {
        if (empty($a['time']) || empty($b['time'])) {
          $cmp = (empty($a['time']) - empty($b['time']));
        }
        else {
          $cmp = ($b['time'] - $a['time']);
        }

        if ($cmp === 0) {
          // never assume equality; fallback to alphabetical order
          return $alphaSortClosure($a, $b);
        }

        return $cmp * (($order == 'desc') ? 1 : -1);
      });
    }
    else {
      // sort alphabetically
      uasort($this->pages, $alphaSortClosure);
    }
  }

  protected function discoverCurrentPage() {
    $pageIds = array_keys($this->pages);

    $contentDir = $this->configHandler->getConfig('content_dir');
    $contentExt = $this->configHandler->getConfig('content_ext');
    $currentPageId = substr($this->requestFile, strlen($contentDir), -strlen($contentExt));
    $currentPageIndex = array_search($currentPageId, $pageIds);
    if ($currentPageIndex !== FALSE) {
      $this->currentPage = &$this->pages[$currentPageId];

      if (($this->configHandler->getConfig('order_by') == 'date') && ($this->configHandler->getConfig('order') == 'desc')) {
        $previousPageOffset = 1;
        $nextPageOffset = -1;
      }
      else {
        $previousPageOffset = -1;
        $nextPageOffset = 1;
      }

      if (isset($pageIds[$currentPageIndex + $previousPageOffset])) {
        $previousPageId = $pageIds[$currentPageIndex + $previousPageOffset];
        $this->previousPage = &$this->pages[$previousPageId];
      }

      if (isset($pageIds[$currentPageIndex + $nextPageOffset])) {
        $nextPageId = $pageIds[$currentPageIndex + $nextPageOffset];
        $this->nextPage = &$this->pages[$nextPageId];
      }
    }
  }

  protected function getAbsolutePath($path) {
    if (substr($path, 0, 1) !== '/') {
      $path = $this->getRootDir() . $path;
    }
    return rtrim($path, '/') . '/';
  }

}
