<?php
namespace giantbits\crelish\components;

use yii\data\ActiveDataProvider;

class CrelishDataResolver  {

    public static function resolveModel($modelInfo) {

        if(strpos($modelInfo['ctype'], 'db:') !== false) {
            $model = str_replace('db:', '', $modelInfo['ctype']);
            return call_user_func_array('app\workspace\models\\'. ucfirst($model) . '::find', ['uuid' => $modelInfo['uuid']])->one();
        }

        return new CrelishDynamicJsonModel([], ['ctype' => $modelInfo['ctype'], 'uuid' => $modelInfo['uuid']]);
    }

    public static function resolveProvider($ctype, $options) {

        if(strpos($ctype, 'db:') !== false) {
            $model = str_replace('db:', '', $ctype);

            return new ActiveDataProvider([
                'query' => call_user_func('app\workspace\models\\'. ucfirst($model) . '::find')->where($options['filter']),
                'key' => 'uuid',
            ]);
        }

        return new CrelishJsonDataProvider($ctype, $options);

    }
}
