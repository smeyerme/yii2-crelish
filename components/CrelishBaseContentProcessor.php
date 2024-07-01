<?php
namespace giantbits\crelish\components;

use yii\base\Component;
use yii\helpers\VarDumper;
use function _\find;

class CrelishBaseContentProcessor extends Component
{
  public $data;

  public static function processData($key, $data, &$processedData)
  {
    $processedData = $processedData;
  }

  public static function processJson($key, $data, &$processedData)
  {
    $processedData = $processedData;
  }

  public static function processContent($ctype, $data)
  {
    $processedData = [];
    $elementDefinition = CrelishDynamicJsonModel::loadElementDefinition($ctype);
		
	  if ($data) {
		  if($ctype === 'widget' && !empty($data->options)) {
			  $widgetOptions = $data->options;
		  }

      foreach ($data as $key => $content) {
        $fieldTypeOrig = find($elementDefinition->fields, function ($def) use ($key) {
          return $def->key == $key;
        });
				
        $transform = NULL;
        if (!empty($fieldTypeOrig) && is_object($fieldTypeOrig)) {
          $fieldType = (property_exists($fieldTypeOrig, 'type')) ? $fieldTypeOrig->type : 'textInput';
          $transform = (property_exists($fieldTypeOrig, 'transform')) ? $fieldTypeOrig->transform : null;
        }
				
        if (!empty($fieldType)) {
	        // Get processor class.
          $processorClass = 'giantbits\crelish\plugins\\' . strtolower($fieldType) . '\\' . ucfirst($fieldType) . 'ContentProcessor';
          if(!empty($transform)) $transformClass = 'giantbits\crelish\components\transformer\CrelishFieldTransformer' . ucfirst($transform);

          if (strpos($fieldType, "widget_") !== false) {
            $processorClass = str_replace("widget_", "", $fieldType) . 'ContentProcessor';
          }

          if (class_exists($processorClass)) {
	          if(!empty($widgetOptions)) {
		          $content .= '|' . $widgetOptions;
	          }
            $processorClass::processData($key, $content, $processedData);
          } else {
            $processedData[$key] = $content;
          }
        }

        if (!empty($transform) && class_exists($transformClass)) {
          $transformClass::afterFind($processedData[$key]);
        }
      }
    }
    return $processedData;
  }

  public static function processElement($ctype, $data)
  {
    $processedData = [];
    $elementDefinition = CrelishDynamicModel::loadElementDefinition($ctype);

    if ($data) {
      foreach ($data as $attr => $value) {
        CrelishBaseContentProcessor::processFieldData($ctype, $elementDefinition, $attr, $value, $processedData);
      }
    }
    return $processedData;
  }

  public static function processFieldData($ctype, $elementDefinition, $attr, $value, &$finalArr)
  {
    $fieldType = 'textInput';
		
    // Get type of field.
    $field = find($elementDefinition->fields, function ($value) use ($attr) {
      return $value->key == $attr;
    });
		
	  $transform = NULL;
    if (!empty($field) && is_object($field)) {
      $fieldType = (property_exists($field, 'type')) ? $field->type : 'textInput';
      $transform = (property_exists($field, 'transform')) ? $field->transform : null;
    }
	  
	  // Get processor class.
    $processorClass = 'giantbits\crelish\plugins\\' . strtolower($fieldType) . '\\' . ucfirst($fieldType) . 'ContentProcessor';
    if(!empty($transform)) $transformClass = 'giantbits\crelish\components\transformer\CrelishFieldTransformer' . ucfirst($transform);
		
    if (strpos($fieldType, "widget_") !== FALSE) {
      $processorClass = str_replace("widget_", "", $fieldType) . 'ContentProcessor';
    }

    if (class_exists($processorClass) && method_exists($processorClass, 'processJson')) {
      $processorClass::processJson($ctype, $attr, $value, $finalArr);
    } else {
      $finalArr[$attr] = $value;
    }
		
    if (!empty($transform) && class_exists($transformClass)) {
      $transformClass::afterFind($finalArr[$attr]);
    }
  }
}
