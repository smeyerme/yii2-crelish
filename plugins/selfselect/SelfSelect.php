<?php

namespace giantbits\crelish\plugins\selfselect;

use giantbits\crelish\components\CrelishDynamicJsonModel;
use giantbits\crelish\components\CrelishJsonDataProvider;
use Underscore\Types\Arrays;
use yii\base\Widget;
use yii\helpers\Json;

class SelfSelect extends Widget
{
    public $data;
    public $rawData;
    public $formKey;
    public $field;
    public $value;
    private $info;
    private $selectData = [];
    private $includeDataType;

    public function init()
    {
        parent::init();

        $this->includeDataType = $this->field->config->ctype;

        if (!empty($this->data)) {
            $this->rawData = $this->data;
            $this->data = $this->processData($this->data);
        } else {
            $this->data = Json::encode([]);
            $this->rawData = [];
        }
    }

    private function processData($data)
    {
        // Load datasource.
        $dataSource = new CrelishJsonDataProvider($this->includeDataType, ['sort'=>['by'=>['systitle','asc']]]);
        $dataSource = $dataSource->rawAll();

        $unique_select = array_unique(array_map(function ($elem) {
            if (!empty($elem[$this->formKey]))
                return $elem[$this->formKey];
        }, $dataSource));

        asort($unique_select);

        foreach ($unique_select as $entry) {
            $this->selectData[$entry] = $entry;
        }

        return $data;
    }

    public function run()
    {

        $isRequired = Arrays::find($this->field->rules, function($rule){
            foreach($rule as $set){
                if($set == 'required') {
                    return true;
                }
            }
            return false;
        });

        return $this->render('selfselect.twig', [
            'formKey' => $this->formKey,
            'field' => $this->field,
            'required' => ($isRequired) ? 'required' : '',
            'selectData' => $this->selectData,
            'selectValue' => $this->rawData,
            'includeDataType' => $this->includeDataType
        ]);
    }
}
