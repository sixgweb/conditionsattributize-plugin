<?php

namespace Sixgweb\ConditionsAttributize\Classes;

use Event;
use Sixgweb\Attributize\Models\Field;
use Sixgweb\Attributize\Models\FieldValue;
use Sixgweb\Conditions\Classes\AbstractConditionerEventHandler;
use Sixgweb\Conditions\Classes\ConditionersManager;

class FieldValueConditionerEventHandler extends AbstractConditionerEventHandler
{

    protected $fieldValues = [];
    protected $classes = [];

    protected function getModelClass(): string
    {
        return \Sixgweb\Attributize\Models\FieldValue::class;
    }

    protected function getControllerClass(): ?string
    {
        //Since some controllers are not fieldable (forms), we need to use the base controller
        //to ensure the field values conditioner is available.
        return \Backend\Classes\Controller::class;
    }

    protected function getFieldConfig(): array
    {
        return [
            'label' => 'Field Value',
            'type' => 'recordfinder',
            'list' => '~/plugins/sixgweb/conditionsattributize/models/fieldvalue/columns.yaml',
            'recordsPerPage' => 10,
            'title' => 'Find Field Value',
            'prompt' => 'Click the Find button to find a field value',
            'keyFrom' => 'id',
            'nameFrom' => 'label',
            'descriptionFrom' => null,
            'searchMode' => 'all',
            'useRelation' => false,
            'modelClass' => 'Sixgweb\Attributize\Models\FieldValue',
            'scope' => 'matchFieldableType',
        ];
    }

    protected function getGroupName(): string
    {
        return 'Field Values';
    }

    protected function getGroupIcon(): string
    {
        return 'bi-list-ul';
    }

    protected function getModelOptions(): array
    {
        return [];
    }

    protected function getConditionerCallback(): ?callable
    {
        return function () {

            /**
             * Before we get the fieldable fields, we need to inject the field values into the conditioners manager.
             * This will allow us to filter the fieldable fields based on the field values.
             */
            Event::listen('sixgweb.attributize.fieldable.getFields', function (&$fields, $model, $options) {


                //Initialize the values from the model
                $modelValues = $model->{$model->fieldableGetColumn()} ?? [];

                //Override the values with post data
                if ($post = post()) {
                    $postValues = $this->extractFieldValuesFromPostData($post);
                    $modelValues = $postValues ? $postValues : $modelValues;
                }

                //Apply the conditioners, if we have field values
                if (!empty($modelValues)) {

                    $query = FieldValue::select('id');
                    $this->addFieldValuesToModelQuery($model, $modelValues, $query);
                    $ids = $query->get()->pluck('id')->toArray();
                    ConditionersManager::instance()->addConditioner([FieldValue::class => $ids]);
                } else {
                    ConditionersManager::instance()->addConditioner([FieldValue::class => '']);
                }
            });
        };
    }

    protected function addFieldValuesToModelQuery($model, $modelValues, $query)
    {
        foreach ($modelValues as $key => $value) {

            if (substr($key, 0, 1) == '_') {
                continue;
            }

            //Handle repeater values
            if (is_array($value) && (isset($value[0]) && is_array($value[0]))) {
                $fieldModel = new Field;
                foreach ($value as $repeaterValue) {
                    $this->addFieldValuesToModelQuery($fieldModel, $repeaterValue, $query);
                }
            } else {
                $query->orWhere(function ($query) use ($model, $key, $value) {
                    if (is_array($value)) {
                        $query->whereIn('value', $value);
                    } else {
                        $query->where('value', $value);
                    }
                    $query->whereHas('field', function ($query) use ($model, $key) {
                        //This will slow the query down and we don't need it applied here.
                        $query->withoutGlobalScope('meetsConditions');
                        $query->where('code', $key);
                        $query->where('fieldable_type', get_class($model));
                    });
                });
            }
        }
    }

    protected function extractFieldValuesFromPostData($data)
    {
        foreach ($data as $key => $value) {
            if ($key == 'field_values') {
                return $value;
            }
            if (is_array($value)) {
                if ($result = $this->extractFieldValuesFromPostData($value)) {
                    return $result;
                }
            }
        }
    }

    protected function enableExactLogic(): bool
    {
        return true;
    }

    protected function enableExactConditionerLogic(): bool
    {
        return true;
    }

    /**
     * We're limiting fieldvalue conditions to inclusive only.  Conditions was created
     * with single model conditioners in mind and having multiple fieldvalue conditioners
     * makes checking for exclusive too complex.
     *
     * @param array $groups
     * @return array
     */
    protected function filterConditionerGroupFields(array $fields): array
    {
        unset($fields['_nullable']);

        $options = $fields['_logic']['options'];
        unset($options['exclusive']);
        $fields['_logic']['options'] = $options;
        return $fields;

        $fields['_logic'] = [
            'type' => 'hint',
            'label' => 'Condition Logic',
            'comment' => 'Field Value conditions are always inclusive and never nullable.  <a href="https://sixgweb.github.io/oc-plugin-documentation/conditions/usage/editor.html" target="_blank">See Documentation</a>',
            'commentHtml' => true,
        ];

        return $fields;
    }
}
