<?php
/**
 * Created by PhpStorm.
 * User: devop
 * Date: 03.02.16
 * Time: 20:57
 */

namespace giantbits\crelish\components;

use Underscore\Parse;
use Underscore\Underscore;
use Underscore\Types\Arrays;
use yii\base\Component;
use yii\data\ArrayDataProvider;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use yii\helpers\Html;
use yii\helpers\VarDumper;
use yii\widgets\LinkPager;
use yii\grid\ActionColumn;
use yii\helpers\Url;

class CrelishJsonDataProvider extends Component
{

    private $ctype;
    private $allModels;
    private $rawModels;
    private $definitions;
    private $key = 'uuid';
    private $uuid;
    private $pathAlias;
    private $pageSize = 20;

    /**
     * Resolve language specific data source folder / file.
     * If no folder for the current language is found take the default language
     * source.
     * @param  string $uuid [description]
     * @return [type]       [description]
     */
    private function resolveDataSource($uuid = '')
    {
        $ds = DIRECTORY_SEPARATOR;
        $fileDataSource = '';

        $langDataFolder = (\Yii::$app->params['defaultLanguage'] != \Yii::$app->language) ? $ds . \Yii::$app->language : '';
        $fileDataSource = \Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $langDataFolder . $ds . $uuid . '.json';

        if (!file_exists(\Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $langDataFolder . $ds . $uuid . '.json')) {
            $fileDataSource = \Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $ds . $uuid . '.json';
        }

        return $fileDataSource;
    }

    public function __construct($ctype, $settings = [], $uuid = null)
    {
        $this->ctype = $ctype;
        $this->pathAlias = ($this->ctype == 'elements') ? '@app/workspace' : '@app/workspace/data';

        if (!empty($uuid)) {
            $this->uuid = $uuid;
            if ($theFile = @file_get_contents($this->resolveDataSource($uuid))) {
                $this->allModels[] = $this->processSingle(\yii\helpers\Json::decode($theFile), true);
            } else {
                $this->allModels[] = [];
            }
        } else {
            $dataModels = \Yii::$app->cache->get('crc_' . $ctype);

            if ($dataModels === false) {
                // $data is not found in cache, calculate it from scratch
                $dataModels = $this->parseFolderContent($this->ctype);
                // store $data in cache so that it can be retrieved next time
                \Yii::$app->cache->set('crc_' . $ctype, $dataModels);
            }

            $this->allModels = $dataModels;
        }

        if (Arrays::has($settings, 'filter')) {
            if (!empty($settings['filter'])) {
                $this->filterModels($settings['filter']);
            }
        }

        if (Arrays::has($settings, 'sort')) {
            if (!empty($settings['sort'])) {
                $this->sortModels($settings['sort']);
            }
        }

        if (Arrays::has($settings, 'limit')) {
            if (!empty($settings['limit'])) {
                $this->pageSize = $settings['limit'];
            }
        }

        parent::__construct();
    }

    private function processSingle($data)
    {
        $finalArr = [];
        $modelArr = (array)$data;

        // Handle specials like in frontend.
        $elementDefinition = $this->getDefinitions();

        // todo: Handle special fields... uncertain about this.
        foreach ($modelArr as $attr => $value) {
            // Get type of field.
            $fieldType = Arrays::find($elementDefinition->fields, function ($value) use ($attr) {
                return $value->key == $attr;
            });

            if (!empty($fieldType) && is_object($fieldType)) {
                $fieldType = $fieldType->type;
            }

            // Get processor class.
            $processorClass = 'giantbits\crelish\plugins\\' . strtolower($fieldType) . '\\' . ucfirst($fieldType) . 'ContentProcessor';

            if (strpos($fieldType, "widget_") !== false) {
                $processorClass = str_replace("widget_", "", $fieldType) . 'ContentProcessor';
            }

            if (class_exists($processorClass) && method_exists($processorClass, 'processJson')) {
                $processorClass::processJson($this, $attr, $value, $finalArr);
            } else {
                $finalArr[$attr] = $value;
            }
        }

        return $finalArr;
    }

    public function filterModels($filter)
    {
        if (is_array($filter)) {
            foreach ($filter as $key => $keyValue) {
                if (is_array($keyValue)) {
                    if ($keyValue[0] == 'strict') {
                        $this->allModels = Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
                            return $value[$key] == $keyValue[1];
                        });
                    }

                    if ($keyValue[0] == 'lt') {
                        $this->allModels = Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
                            return $value[$key] < $keyValue[1];
                        });
                    }

                    if ($keyValue[0] == 'gt') {
                        $this->allModels = Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
                            return $value[$key] > $keyValue[1];
                        });
                    }
                } elseif (is_bool($keyValue)) {
                    $this->allModels = Arrays::filterBy($this->allModels, $key, $keyValue);
                } else {
                    // todo: Optimize filter with param for "like" and "equal" filtering
                    if ($key === 'slug') {
                        $this->allModels = Arrays::filterBy($this->allModels, $key, $keyValue);
                    } else {

                        $this->allModels = Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
                            if (!empty($value[$key]) && is_array($value[$key])) {
                                $value[$key] = Arrays::implode($value[$key], "||");
                            } elseif (strpos($key, "|") !== false) {
                                $key = str_replace("|", ".", $key);
                            }

                            $array = new Underscore($value);

                            return (stripos($array->get($key), html_entity_decode($keyValue)) !== false);
                        });
                    }
                }
            }
        }
    }

    /**
     * [sortModels description]
     * @param  [type] $sort [description]
     * @return [type]       [description]
     */
    public function sortModels($sort)
    {
        $sortparams[] = $this->allModels;

        if(is_array($sort['by'])){
            foreach($sort['by'] as $item){
                switch($item) {
                    case 'asc':
                        $sortparams[] = SORT_ASC;
                        break;
                    case 'desc':
                        $sortparams[] = SORT_DESC;
                        break;
                    default:
                        $sortparams[] = $item;
                }
            }
        }

        $this->allModels = call_user_func_array([$this, 'array_orderby'], $sortparams);

        /*
        $this->allModels = Arrays::sort($this->allModels, function ($model) use ($sort) {
            return $model[$sort['by'][0]];
        }, $sort['by'][1]);
        */

    }

    private function array_orderby()
    {
        $args = func_get_args();
        $data = array_shift($args);

        foreach ($args as $n => $field) {
            if (is_string($field)) {
                $tmp = array();
                foreach ($data as $key => $row)
                    $tmp[$key] = $row[$field];
                $args[$n] = $tmp;
            }
        }
        $args[] = &$data;

        @call_user_func_array('array_multisort', $args);
        return array_pop($args);
    }

    /**
     * [parseFolderContent description]
     * @param  [type] $folder [description]
     * @return [type]         [description]
     */
    public function parseFolderContent($folder)
    {
        $filesArr = [];
        $allModels = [];

        $fullFolder = \Yii::getAlias($this->pathAlias) . DIRECTORY_SEPARATOR . $folder;

        if (!file_exists($fullFolder)) {
            FileHelper::createDirectory($fullFolder);
        }

        $files = FileHelper::findFiles($fullFolder, ['recursive' => false]);

        if (isset($files[0])) {
            foreach ($files as $file) {
                $filesArr[] = $file;
            }
        }

        foreach ($filesArr as $file) {
            $finalArr = [];
            $content = file_get_contents($file);
            $modelArr = json_decode($content, true);
            if (is_null($modelArr)) {
                $segments = explode(DIRECTORY_SEPARATOR, $file);
                CrelishBaseController::addError("Invalid JSON in " . array_pop($segments));
                continue;
            }
            $modelArr['id'] = $file;
            $modelArr['ctype'] = $this->ctype;

            // Handle specials like in frontend.
            $elementDefinition = $this->getDefinitions();

            foreach ($modelArr as $attr => $value) {
                // Get type of field.
                $fieldType = Arrays::find($elementDefinition->fields, function ($value) use ($attr) {
                    return $value->key == $attr;
                });

                if (!empty($fieldType) && is_object($fieldType)) {
                    $fieldType = $fieldType->type;
                }

                // Get processor class.
                $processorClass = 'giantbits\crelish\plugins\\' . strtolower($fieldType) . '\\' . ucfirst($fieldType) . 'ContentProcessor';

                if (strpos($fieldType, "widget_") !== false) {
                    $processorClass = str_replace("widget_", "", $fieldType) . 'ContentProcessor';
                }

                if (class_exists($processorClass) && method_exists($processorClass, 'processJson')) {
                    $processorClass::processJson($this, $attr, $value, $finalArr);
                } else {
                    $finalArr[$attr] = $value;
                }
            }

            $allModels[] = $finalArr;
        }

        return $allModels;
    }

    public function all()
    {
        $provider = new ArrayDataProvider([
            'key' => 'id',
            'allModels' => $this->allModels,
            'pagination' => [
                'totalCount' => count($this->allModels),
                'pageSize' => $this->pageSize,
                'forcePageParam' => true
            ],
        ]);

        $models = $provider->getModels();

        $pager = LinkPager::widget([
            'pagination' => $provider->getPagination(),
            'maxButtonCount' => 10
        ]);

        $result = ['models' => array_values($models), 'pager' => $pager];

        return $result;
    }

    public function rawAll()
    {
        return $this->allModels;
    }

    public function one()
    {
        if (!empty($this->allModels[0])) {
            return $this->allModels[0];
        }

        return null;
    }

    public function raw()
    {
        $provider = new ArrayDataProvider([
            'key' => $this->key,
            'allModels' => $this->allModels,
            'sort' => [
                'attributes' => [$this->key, 'systitle'],
            ],
            'pagination' => [
                'totalCount' => count($this->allModels),
                'pageSize' => $this->pageSize,
                'forcePageParam' => true,
                'route' => (!empty(\Yii::$app->getRequest()->getQueryParam('pathRequested'))) ? '/' . \Yii::$app->getRequest()->getQueryParam('pathRequested') : null,
                'params' => [
                    'page' => !empty($_GET['page']) ? $_GET['page'] : '',
                    'category' => !empty($_GET['category']) ? $_GET['category'] : '',
                    'branch' => !empty($_GET['branch']) ? $_GET['branch'] : '',
                    'title' => !empty($_GET['title']) ? $_GET['title'] : '',
                    'sort' => !empty($_GET['sort']) ? $_GET['sort'] : '',
                    'per-page' => !empty($_GET['per-page']) ? $_GET['per-page'] : '',
                    'kind' => !empty($_GET['kind']) ? $_GET['kind'] : '',
                    'ctype' => !empty($_GET['ctype']) ? $_GET['ctype'] : '',
                ]
            ],
        ]);

        return $provider;
    }

    public function delete()
    {
        $ds = DIRECTORY_SEPARATOR;
        if (@unlink(\Yii::getAlias($this->pathAlias) . $ds . $this->type . $ds . $this->uuid . '.json')) {
            \Yii::$app->cache->flush();
        }

        return;
    }

    public function getDefinitions()
    {
        $this->definitions = new \stdClass();
        $this->definitions->fields = [];

        if ($this->ctype !== 'elements') {
            $filePath = \Yii::getAlias('@app/workspace/elements') . DIRECTORY_SEPARATOR . $this->ctype . '.json';

            // Add core fields.
            $this->definitions->fields[] = Json::decode('{ "label": "UUID", "key": "uuid", "type": "textInput", "visibleInGrid": false, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
            $this->definitions->fields[] = Json::decode('{ "label": "ctype", "key": "ctype", "type": "textInput", "visibleInGrid": false, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
            $this->definitions->fields = array_merge($this->definitions->fields, Json::decode(file_get_contents($filePath), false)->fields);
            $this->definitions->fields[] = Json::decode('{ "label": "State", "key": "state", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', false);
            //$this->definitions->fields[] = Json::decode('{ "label": "Created", "key": "created", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
            //$this->definitions->fields[] = Json::decode('{ "label": "Updated", "key": "updated", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
            //$this->definitions->fields[] = Json::decode('{ "label": "Publish from", "key": "from", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
            //$this->definitions->fields[] = Json::decode('{ "label": "Publish to", "key": "to", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
            //$this->definitions->fields[] = Json::decode('{ "label": "Element", "key": "elementType", "type": "textInput", "visibleInGrid": false, "rules": [["string", {"max": 128}]]}', false);
        }

        return $this->definitions;
    }

    public function getColumns()
    {
        $columns = [];

        foreach ($this->getDefinitions()->fields as $field) {
            if (!empty($field->visibleInGrid) && $field->visibleInGrid) {
                $columns[] = $field->key;
            }
        }

        $columns[] = [
            'class' => ActionColumn::className(),
            'template' => '{update}',
            'buttons' => [
                'update' => function ($url, $model) {
                    return Html::a('<span class="glyphicon glyphicon-edit"></span>', $url, [
                        'title' => \Yii::t('app', 'Edit'),
                        'data-pjax' => '0'
                    ]);
                }
            ],
            'urlCreator' => function ($action, $model, $key, $index) {
                if ($action === 'update') {
                    $url = Url::to(['content/update', 'ctype' => $this->ctype, 'uuid' => $model['uuid']]);
                    return $url;
                }
            }
        ];

        return array_values($columns);
    }
}
