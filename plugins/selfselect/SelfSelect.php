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
            $this->data = $this->processData();
            $this->rawData = "";
        }
    }

    private function processData($data = null)
    {
        // Load datasource.
        $dataSource = new CrelishJsonDataProvider($this->includeDataType, ['sort'=>['by'=>['systitle','asc']]]);
        $dataSource = $dataSource->rawAll();

        foreach ($dataSource as $item) {

            if(!empty($item[$this->formKey])){
                $this->selectData[$item[$this->formKey]] = $item[$this->formKey];
            } else {

            }
        }

        asort($this->selectData);

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
