<?php

namespace giantbits\crelish\plugins\feedconfigurator;

use giantbits\crelish\components\CrelishFormWidget;
use yii\helpers\Json;

/**
 * A CMS form widget that renders a dynamic configuration form
 * based on a linked dropdown field's selected value.
 *
 * Each value in the linked dropdown maps to a different config schema.
 * The schemas follow the same format as ConfigurableWidgetInterface::getConfigSchema().
 *
 * Element JSON config example:
 * {
 *   "key": "feedConfig",
 *   "type": "feedconfigurator",
 *   "config": {
 *     "linkedField": "feedType",
 *     "schemas": {
 *       "onlyfy": { "authToken": { "type": "text", "label": "Auth Token" }, ... },
 *       "json": { ... },
 *       "xml": { ... }
 *     }
 *   }
 * }
 */
class Feedconfigurator extends CrelishFormWidget
{
    public $data;
    public $formKey;
    public $field;
    public $model;

    public function init()
    {
        parent::init();
    }

    public function run()
    {
        $linkedField = $this->field->config->linkedField ?? 'feedType';
        $schemas = (array)($this->field->config->schemas ?? []);

        // Convert schema objects to arrays for JSON encoding
        $schemasArray = [];
        foreach ($schemas as $key => $schema) {
            $schemasArray[$key] = json_decode(json_encode($schema), true);
        }

        // Decode current data
        $currentOptions = [];
        if (!empty($this->data)) {
            if (is_string($this->data)) {
                $decoded = json_decode($this->data, true);
                $currentOptions = is_array($decoded) ? $decoded : [];
            } elseif (is_array($this->data)) {
                $currentOptions = $this->data;
            }
        }

        // Determine current linked field value from model
        $currentType = '';
        if ($linkedField && isset($this->model->$linkedField)) {
            $currentType = $this->model->$linkedField;
        }

        $fieldId = 'feedconf_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $this->formKey);

        return $this->render('feedconfigurator.twig', [
            'formKey' => $this->formKey,
            'field' => $this->field,
            'fieldId' => $fieldId,
            'linkedField' => $linkedField,
            'schemas' => $schemasArray,
            'currentOptions' => $currentOptions,
            'currentType' => $currentType,
        ]);
    }
}
