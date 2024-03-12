<?php

namespace Sixgweb\ConditionsAttributize\Classes;

use Event;
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
        //All backend controllers
        return \Sixgweb\Attributize\FormWidgets\Attributize::class;
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

            Event::listen('sixgweb.attributize.fieldable.getFields', function (&$fields, $model, $options) {
                $modelValues = $model->{$model->fieldableGetColumn()} ?? [];
                if ($post = post()) {
                    $postValues = $this->extractFieldValuesFromPostData($post);
                    $modelValues = $postValues ? $postValues : $modelValues;
                }

                if (!empty($modelValues)) {
                    $fieldValues = FieldValue::select('id');
                    foreach ($modelValues as $key => $value) {

                        //TODO: work on repeaters
                        if (is_array($value) && is_array($value[0])) {
                            continue;
                        }

                        $fieldValues->orWhere(function ($query) use ($model, $key, $value) {
                            if (is_array($value)) {
                                $query->whereIn('value', $value);
                            } else {
                                $query->where('value', $value);
                            }
                            $query->whereHas('field', function ($query) use ($model, $key) {
                                $query->where('code', $key);
                                $query->where('fieldable_type', get_class($model));
                            });
                        });
                    }
                    $fieldValues = $fieldValues->get();
                    $ids = $fieldValues->pluck('id')->toArray();
                    ConditionersManager::instance()->addConditioner([FieldValue::class => $ids]);
                } else {
                    ConditionersManager::instance()->addConditioner([FieldValue::class => '']);
                }
            });

            Event::listen('sixgweb.attributize.fieldable.afterGetFieldss', function (&$fields, $model, $options) {
                if (isset($this->classes[get_class($model)])) {
                    return;
                }

                if ($model->exists) {
                    $this->fieldValues = empty($this->fieldValues) ? $model->{$model->fieldableGetColumn()} : $this->fieldValues;
                }
                $this->classes[get_class($model)] = true;
                $fields = $model->fieldableGetFields($options);
            });

            $this->getModelClass()::extend(function ($model) {
                $model->bindEvent('model.afterFetchs', function () use ($model) {
                    if ($post = post()) {
                        $fieldValues = $this->extractFieldValuesFromPostData($post);
                        if ($fieldValues) {
                            $this->fieldValues = $fieldValues;
                        }
                    }

                    if (empty($this->fieldValues)) {
                        $conditionersManager = ConditionersManager::instance();
                        $conditionersManager->addConditioner([$this->getModelClass() => '']);
                        return;
                    }

                    if (!$model->field) {
                        return;
                    }

                    if (isset($this->fieldValues[$model->field->code])) {
                        $fieldValue = $this->fieldValues[$model->field->code];
                        $match = is_array($fieldValue) ? in_array($model->value, $fieldValue) : $fieldValue == $model->value;
                        if ($match) {
                            $conditionersManager = ConditionersManager::instance();
                            $conditionersManager->addConditioner($model);
                        }
                    }

                    /**
                     * Fieldable will only be set for repeater fields.  Other fields have no fieldable_id value.
                     */
                    if ($model->field->fieldable) {
                        $fieldable = $model->field->fieldable;
                        if (isset($fieldValues[$fieldable->code]) && is_array($fieldValues[$fieldable->code])) {
                            foreach ($fieldValues[$fieldable->code] as $array) {
                                if (isset($array[$model->field->code]) && $array[$model->field->code]) {
                                    if ($array[$model->field->code] == $model->value) {
                                        $conditionersManager = ConditionersManager::instance();
                                        $conditionersManager->addConditioner($model);
                                    }
                                }
                            }
                        }
                    }
                });
            });
        };
    }

    protected function extractFieldValuesFromPostData($data)
    {
        foreach ($data as $key => $value) {
            if ($key == 'field_values') {
                return $value;
            }
            if (is_array($value)) {
                return $this->extractFieldValuesFromPostData($value);
            }
        }
    }
}
