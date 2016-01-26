<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 30.11.15
 * Time: 18:21
 */

namespace giantbits\crelish\components;

use app\assets\AppAsset;
use yii\base\Component;
use yii\helpers\FileHelper;

class CrelishFileHandler extends Component
{

    protected $config;
    protected $pageFiles;
    protected $content;

    public function getBaseUrl($config = NULL)
    {
        if ($config != NULL) {
            $baseUrl = $config->getConfig('base_url');
            if (!empty($baseUrl)) {
                return $baseUrl;
            }

            if (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off')
                || ($_SERVER['SERVER_PORT'] == 443)
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')
            ) {
                $protocol = 'https';
            } else {
                $protocol = 'http';
            }

            $config->config['base_url'] =
                $protocol . "://" . $_SERVER['HTTP_HOST']
                . dirname($_SERVER['SCRIPT_NAME']) . '/';

            return $config->getConfig('base_url');
        } else {
            return NULL;
        }

    }

    public function setConfig($config)
    {
        $this->config = $config;
    }

    protected function getPageFiles()
    {

        $pageFiles = FileHelper::findFiles(\Yii::$app->params['pageFilePath']);

        return $pageFiles;
    }

    protected function getConfigFile()
    {
        $pageFiles = FileHelper::findFiles(\Yii::$app->params['pageFilePath']);
        return $pageFiles;
    }

    public function parseFileMeta($rawContent, array $headers = [], $file = NULL)
    {
        $session = \Yii::$app->session;
        $meta = array();
        $pattern = "/^(\/(\*)|---)[[:blank:]]*(?:\r)?\n"
            . "(.*?)(?:\r)?\n(?(2)\*\/|---)[[:blank:]]*(?:(?:\r)?\n|$)/s";
        if (preg_match($pattern, $rawContent, $rawMetaMatches)) {
            $yamlParser = new \Symfony\Component\Yaml\Parser();
            $meta = $yamlParser->parse($rawMetaMatches[3]);
            $meta = array_change_key_case($meta, CASE_LOWER);

            foreach ($headers as $fieldId => $fieldName) {
                $fieldName = strtolower($fieldName);
                if (isset($meta[$fieldName])) {
                    // rename field (e.g. remove whitespaces)
                    if ($fieldId != $fieldName) {
                        $meta[$fieldId] = $meta[$fieldName];
                        unset($meta[$fieldName]);
                    }
                } else {
                    // guarantee array key existance
                    $meta[$fieldId] = '';
                }
            }

            if (!empty($meta['date'])) {
                $meta['time'] = strtotime($meta['date']);
                $meta['date_formatted'] = utf8_encode(strftime($this->config->getConfig('date_format'), $meta['time']));
            } else {
                $meta['time'] = $meta['date_formatted'] = '';
            }
        } else {
            // guarantee array key existance
            foreach ($headers as $id => $field) {
                $meta[$id] = '';
            }

            $meta['time'] = $meta['date_formatted'] = '';
        }

        // Add usefull stuff.
        $meta['self_url'] = \Yii::$app->urlManager->createAbsoluteUrl([preg_replace('/^[0-9]+\.+/', '', $this->getSegmentFromPath($file))]);
        $meta['back_url'] = \Yii::$app->request->referrer;

        return $meta;
    }

    public static function getSegmentFromPath($path)
    {

        if (!$path) {
            return '#';
        }

        $removeSegment = \Yii::$app->basePath . DIRECTORY_SEPARATOR . 'workspace' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR;
        $segmentSrc = str_replace($removeSegment, '', $path);
        $segment = substr($segmentSrc, 0, strrpos($segmentSrc, "/"));

        return $segment;
    }

    public function getFileMeta()
    {
        return $this->meta;
    }

    public function prepareFileContent($rawContent, array $meta, $isModular = FALSE)
    {
        // remove meta header
        $metaHeaderPattern = "/^(\/(\*)|---)[[:blank:]]*(?:\r)?\n"
            . "(.*?)(?:\r)?\n(?(2)\*\/|---)[[:blank:]]*(?:(?:\r)?\n|$)/s";
        $content = preg_replace($metaHeaderPattern, '', $rawContent, 1);

        // replace %site_title%
        $content = str_replace('%site_title%', $this->config->getConfig('site_title'), $content);
        $content = str_replace('%base_url%', rtrim($this->getBaseUrl(), '/'), $content);

        $assetBundle = AppAsset::register(\Yii::$app->view);
        $content = str_replace('%asset_url%', $assetBundle->baseUrl, $content);

        // Replace %self_url% with link to it self.
        $content = str_replace('%self_url%?', $this->getBaseUrl(), $content);

        // replace %theme_url%
        $themeUrl = $this->getBaseUrl() . basename($this->config->getThemesDir()) . '/' . $this->config->getConfig('theme');
        $content = str_replace('%theme_url%', $themeUrl, $content);

        // replace %meta.*%
        if (!empty($meta) && !$isModular) {
            $metaKeys = $metaValues = array();
            foreach ($meta as $metaKey => $metaValue) {
                if (is_scalar($metaValue) || ($metaValue === NULL)) {
                    \Yii::$app->view->registerMetaTag([
                        'name' => $metaKey,
                        'content' => strval($metaValue)
                    ]);
                }
            }
            $content = str_replace($metaKeys, $metaValues, $content);
        }

        return $content;
    }

    public function parseFileContent($content)
    {
        return \yii\helpers\Markdown::process($content, 'extra');
    }

    public function getFileContent()
    {
        return $this->out;
    }

    public function getRequestFile()
    {
        return $this->requestFile;
    }

    public function loadFileContent($file)
    {
        if(!file_exists($file)) {
            $file = str_replace('.' . \Yii::$app->language . '.md', '.md', $file);
        }

        if(!file_exists($file)) {
            throw new \yii\web\NotFoundHttpException();
        }

        return file_get_contents($file);
    }

    public function selectTemplate($requestUrl, $meta, $file = NULL)
    {
        $template = (!empty($file) ? 'modular/' : '') . 'default.mustache';

        $requestTemplate = $requestUrl;


        if (!empty($file)) {
            $pathArr = explode("/", $file);
            $segment = count($pathArr) - 2;
            $requestTemplate = preg_replace('/[0-9]+/', '', $pathArr[$segment]);
        }

        // Check if there is a template/view by the name of the requested path.
        if (file_exists(\Yii::$app->view->theme->basePath . '/frontend/' . (!empty($file) ? 'modular/' : '') . $requestTemplate)) {
            $template = (!empty($file) ? 'modular/' : '') . $requestTemplate;
        }

        if (file_exists(\Yii::$app->view->theme->basePath . '/frontend/' . (!empty($file) ? 'modular/' : '') . $requestTemplate . '.mustache')) {
            $template = (!empty($file) ? 'modular/' : '') . $requestTemplate . '.mustache';
        }

        // Manually defined templates have highest priority.
        if (!empty($meta['template'])) {

            if (file_exists(\Yii::$app->view->theme->basePath . '/frontend/' . (!empty($file) ? 'modular/' : '') . $meta['template'] . '.mustache')) {
                $template = (!empty($file) ? 'modular/' : '') . $meta['template'] . '.mustache';
            } else {
                $template = $meta['template'];
            }
        }

        return $template;

    }

    public function parseFolderContent($path)
    {
        $filesArr = [];

        $files = FileHelper::findFiles($path);
        if (isset($files[0])) {
            foreach ($files as $file) {
                $filesArr[] = $file;
            }
        }

        return $filesArr;
    }

    public function buildPageCollection()
    {

        $pagesSimple = [];
        $pageTree = [];

        $sources = $this->parseFolderContent(\Yii::$app->basePath . DIRECTORY_SEPARATOR . $this->config->config['content_dir']);
        sort($sources);

        foreach ($sources as $page) {

            $pagePathRelative = str_replace(\Yii::$app->basePath . DIRECTORY_SEPARATOR . $this->config->config['content_dir'], "", $page);
            $pageLevel = substr_count($pagePathRelative, '/');
            $pageId = preg_replace('/^[0-9]+\.+/', '', substr($pagePathRelative, 0, strrpos($pagePathRelative, '/')));

            $pagePathRelative = \Yii::$app->urlManager->createUrl([$pagePathRelative, 'language    ' => \Yii::$app->language]);

            $pagesSimple[$pageId] = [
                'pathOrig' => str_replace('.md', '.' . \Yii::$app->language . '.md', $page),
                'pathRelative' => $pagePathRelative,
                'structureLevel' => $pageLevel
            ];
        }

        foreach ($pagesSimple as $id => $page) {
            $pathArray = $this->pathToArray($id, $page);
            $pageTree = array_merge_recursive($pageTree, $pathArray);
        }


        return $pageTree;
    }

    private function pathToArray($path, $value)
    {
        $separator = '/';
        $pos = strpos($path, $separator);

        if ($pos === false) {
            return [$path => $value];
        }

        $key = substr($path, 0, $pos);
        $path = substr($path, $pos + 1);

        $result = array(
            $key => $this->pathToArray($path, $value),
        );

        return $result;
    }
}
