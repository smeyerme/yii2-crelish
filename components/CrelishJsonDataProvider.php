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
use yii\base\DynamicModel;
use yii\data\ArrayDataProvider;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use yii\helpers\Html;
use yii\helpers\VarDumper;
use yii\widgets\LinkPager;
use yii\grid\ActionColumn;
use yii\helpers\Url;

class CrelishJsonDataProvider extends Component {

    private $ctype;
    private $allModels;
    private $rawModels;
    private $definitions;
    private $key = 'uuid';
    private $uuid;
    private $pathAlias;
    private $pageSize = 20;

    public function __construct($ctype, $settings = [], $uuid = NULL) {
        $this->ctype = $ctype;
        $this->pathAlias = ($this->ctype == 'elements') ? '@app/workspace' : '@app/workspace/data';

        if (!empty($uuid)) {
            $this->uuid = $uuid;
            if ($theFile = @file_get_contents($this->resolveDataSource($uuid))) {
                $this->allModels[] = $this->processSingle(\yii\helpers\Json::decode($theFile), TRUE);
            }
            else {
                $this->allModels[] = [];
            }
        }
        else {
            $dataModels = \Yii::$app->cache->get('crc_' . $ctype);

            if ($dataModels === FALSE) {
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

    public function parseFolderContent($folder) {
        $filesArr = [];
        $allModels = [];

        $fullFolder = \Yii::getAlias($this->pathAlias) . DIRECTORY_SEPARATOR . $folder;

        if (!file_exists($fullFolder)) {
            FileHelper::createDirectory($fullFolder);
        }

        $files = FileHelper::findFiles($fullFolder, ['recursive' => FALSE]);

        if (isset($files[0])) {
            foreach ($files as $file) {
                $filesArr[] = $file;
            }
        }

        foreach ($filesArr as $file) {
            $finalArr = [];
            $content = file_get_contents($file);
            $modelArr = json_decode($content, TRUE);
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
                $this->processFieldData($elementDefinition, $attr, $value, $finalArr);
            }

            $allModels[] = $finalArr;
        }

        return $allModels;
    }

    private function processSingle($data) {
        $finalArr = [];
        $modelArr = (array) $data;

        // Handle specials like in frontend.
        $elementDefinition = $this->getDefinitions();

        // todo: Handle special fields... uncertain about this.
        foreach ($modelArr as $attr => $value) {
            $this->processFieldData($elementDefinition, $attr, $value, $finalArr);
        }

        return $finalArr;
    }

    public function filterModels($filter) {

        if (is_array($filter)) {
            foreach ($filter as $key => $keyValue) {
                if (!empty($keyValue)) {
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
                    }
                    elseif (is_bool($keyValue)) {
                        $this->allModels = Arrays::filterBy($this->allModels, $key, $keyValue);
                    }
                    else {
                        // todo: Optimize filter with param for "like" and "equal" filtering
                        if ($key === 'slug') {
                            $this->allModels = Arrays::filterBy($this->allModels, $key, $keyValue);
                        }
                        elseif ($key === 'freesearch') {
                            $this->allModels = Arrays::filter($this->allModels, function ($value) use ($keyValue) {
                                $isMatch = TRUE;

                                $itemString = strtolower(implode("#", Arrays::flatten($value)));
                                $searchFragments = explode(" ", trim($keyValue));

                                foreach ($searchFragments as $fragment) {
                                    if (strpos($itemString, strtolower($fragment)) === FALSE) {
                                        $isMatch = FALSE;
                                    }
                                }

                                return $isMatch;
                            });

                        }
                        else {
                            $this->allModels = Arrays::filter($this->allModels, function ($value) use ($key, $keyValue) {
                                if (!empty($value[$key]) && is_array($value[$key])) {
                                    $value[$key] = Arrays::implode($value[$key], "||");
                                }
                                elseif (strpos($key, "|") !== FALSE) {
                                    $key = str_replace("|", ".", $key);
                                }

                                $array = new Underscore($value);

                                return (stripos($array->get($key), html_entity_decode($keyValue)) !== FALSE);
                            });
                        }
                    }
                }
            }
        }
    }

    public function sortModels($sort) {
        $sortparams[] = $this->allModels;

        if (is_array($sort['by'])) {
            foreach ($sort['by'] as $item) {
                switch ($item) {
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

        $this->allModels = call_user_func_array([
            $this,
            'array_orderby'
        ], $sortparams);
    }

    public function all() {
        $provider = new ArrayDataProvider([
            'key' => 'id',
            'allModels' => $this->allModels,
            'pagination' => [
                'totalCount' => count($this->allModels),
                'pageSize' => $this->pageSize,
                'forcePageParam' => TRUE
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

    public function one() {
        if (!empty($this->allModels[0])) {
            return $this->allModels[0];
        }

        return NULL;
    }

    public function raw() {

        $getParams = $_GET;

        $provider = new ArrayDataProvider([
            'key' => $this->key,
            'allModels' => $this->allModels,
            'sort' => $this->getSorting(),
            'pagination' => [
                'totalCount' => count($this->allModels),
                'pageSize' => $this->pageSize,
                'forcePageParam' => TRUE,
                'route' => (!empty(\Yii::$app->getRequest()
                    ->getQueryParam('pathRequested'))) ? '/' . \Yii::$app->getRequest()
                        ->getQueryParam('pathRequested') : NULL,
                'params' => array_merge([
                    'page' => !empty($_GET['page']) ? $_GET['page'] : '',
                    'category' => !empty($_GET['category']) ? $_GET['category'] : '',
                    'branch' => !empty($_GET['branch']) ? $_GET['branch'] : '',
                    'title' => !empty($_GET['title']) ? $_GET['title'] : '',
                    'sort' => !empty($_GET['sort']) ? $_GET['sort'] : '',
                    'per-page' => !empty($_GET['per-page']) ? $_GET['per-page'] : '',
                    'kind' => !empty($_GET['kind']) ? $_GET['kind'] : '',
                    'ctype' => !empty($_GET['ctype']) ? $_GET['ctype'] : '',
                ], $getParams)
            ],
        ]);

        return $provider;
    }

    public function rawAll() {
        return $this->allModels;
    }

    public function delete() {
        $ds = DIRECTORY_SEPARATOR;
        if (@unlink(\Yii::getAlias($this->pathAlias) . $ds . $this->type . $ds . $this->uuid . '.json')) {
            \Yii::$app->cache->flush();
        }

        return;
    }

    public function getDefinitions() {

        $this->definitions = new \stdClass();
        $this->definitions->fields = [];

        if ($this->ctype !== 'elements') {
            $filePath = \Yii::getAlias('@app/workspace/elements') . DIRECTORY_SEPARATOR . $this->ctype . '.json';
            $elementStructure = Json::decode(file_get_contents($filePath), FALSE);

            // Add core fields.
            $this->definitions->fields[] = Json::decode('{ "label": "UUID", "key": "uuid", "type": "textInput", "visibleInGrid": false, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', FALSE);
            $this->definitions->fields[] = Json::decode('{ "label": "ctype", "key": "ctype", "type": "textInput", "visibleInGrid": false, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', FALSE);
            $this->definitions->fields = array_merge($this->definitions->fields, $elementStructure->fields);
            $this->definitions->fields[] = Json::decode('{ "label": "State", "key": "state", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]], "options": {"disabled":true}}', FALSE);
            //$this->definitions->fields[] = Json::decode('{ "label": "Created", "key": "created", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
            //$this->definitions->fields[] = Json::decode('{ "label": "Updated", "key": "updated", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
            //$this->definitions->fields[] = Json::decode('{ "label": "Publish from", "key": "from", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
            //$this->definitions->fields[] = Json::decode('{ "label": "Publish to", "key": "to", "type": "textInput", "visibleInGrid": true, "rules": [["string", {"max": 128}]]}', false);
            //$this->definitions->fields[] = Json::decode('{ "label": "Element", "key": "elementType", "type": "textInput", "visibleInGrid": false, "rules": [["string", {"max": 128}]]}', false);

            if (property_exists($elementStructure, 'sortDefault')) {
                $this->definitions->sortDefault = $elementStructure->sortDefault;
            }
        }

        return $this->definitions;
    }

    public function getSorting() {
        $sorting = [];
        $attributes = [];

        if (!empty($this->definitions)) {
            foreach ($this->definitions->fields as $field) {
                if (property_exists($field, 'sortable') && $field->sortable == TRUE) {
                    if (!is_array($field->sortable)) {
                        $attributes[] = (property_exists($field, 'gridField') && !empty($field->gridField)) ? $field->gridField : $field->key;
                    }
                    else {
                        $attributes[$field->key] = [];

                        if (property_exists($field, 'sortDefault')) {
                            $attributes[$field->key]['default'] = constant($field->sortDefault);
                        }
                    }
                }
            }

            $sorting['attributes'] = $attributes;


            if (property_exists($this->definitions, "sortDefault")) {
                foreach ($this->definitions->sortDefault as $key => $value) {
                    $sorting['defaultOrder'] = [$key => constant($value)];
                }
            }
        }

        return $sorting;
    }

    public function getFilters() {

        $model = new CrelishDynamicJsonModel(['systitle'], [
            'ctype' => $this->ctype
        ]);

        if (!empty($_GET['CrelishDynamicJsonModel'])) {
            foreach ($_GET['CrelishDynamicJsonModel'] as $filter => $value) {
                if (!empty($value)) {
                    $filters[$filter] = $value;
                    $_GET[$filter] = $value;
                }
            }
        }

        if (!empty($_GET['CrelishDynamicJsonModel'])) {
            $model->attributes = $_GET['CrelishDynamicJsonModel'];
        }

        return $model;
    }

    public function getColumns() {
        $columns = [];

        foreach ($this->getDefinitions()->fields as $field) {
            if (!empty($field->visibleInGrid) && $field->visibleInGrid) {
                $label = (property_exists($field, 'label') && !empty($field->label)) ? $field->label : null;
                $format = (property_exists($field, 'format') && !empty($field->label)) ? $field->format : 'text';

                $columns[] = (property_exists($field, 'gridField') && !empty($field->gridField)) ? [ 'attribute' => $field->gridField, 'label' => $label, 'format' => $format ] : [ 'attribute' => $field->key, 'label' => $label, 'format' => $format ];
            }
        }

        /*$columns[] = [
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
                    $url = Url::to([
                        'content/update',
                        'ctype' => $this->ctype,
                        'uuid' => $model['uuid']
                    ]);
                    return $url;
                }
            }
        ];*/

        return array_values($columns);
    }

    private function resolveDataSource($uuid = '') {
        $ds = DIRECTORY_SEPARATOR;
        $fileDataSource = '';

        $langDataFolder = (\Yii::$app->params['defaultLanguage'] != \Yii::$app->language) ? $ds . \Yii::$app->language : '';
        $fileDataSource = \Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $langDataFolder . $ds . $uuid . '.json';

        if (!file_exists(\Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $langDataFolder . $ds . $uuid . '.json')) {
            $fileDataSource = \Yii::getAlias($this->pathAlias) . $ds . $this->ctype . $ds . $uuid . '.json';
        }

        return $fileDataSource;
    }

    private function processFieldData($elementDefinition, $attr, $value, &$finalArr) {
        // Get type of field.
        $fieldType = Arrays::find($elementDefinition->fields, function ($value) use ($attr) {
            return $value->key == $attr;
        });

        $transform = NULL;
        if (!empty($fieldType) && is_object($fieldType)) {
            $fieldType = $fieldType->type;
            if (property_exists($fieldType, 'transform')) {
                $transform = $fieldType->transform;
            }
        }

        // Get processor class.
        $processorClass = 'giantbits\crelish\plugins\\' . strtolower($fieldType) . '\\' . ucfirst($fieldType) . 'ContentProcessor';
        $transformClass = 'giantbits\crelish\components\transformer\CrelishFieldTransformer\\' . ucfirst($transform);

        if (strpos($fieldType, "widget_") !== FALSE) {
            $processorClass = str_replace("widget_", "", $fieldType) . 'ContentProcessor';
        }

        if (class_exists($processorClass) && method_exists($processorClass, 'processJson')) {
            $processorClass::processJson($attr, $value, $finalArr);
        }
        else {
            $finalArr[$attr] = $value;
        }

        if (!empty($transform) && class_exists($transformClass)) {
            $transformClass::afterFind($finalArr[$attr]);
        }
    }

    private function array_orderby() {
        $args = func_get_args();
        $data = array_shift($args);

        foreach ($args as $n => $field) {
            if (is_string($field)) {
                $tmp = array();
                foreach ($data as $key => $row) {
                    $tmp[$key] = $row[$field];
                }
                $args[$n] = $tmp;
            }
        }
        $args[] = &$data;

        @call_user_func_array('array_multisort', $args);
        return array_pop($args);
    }
}
