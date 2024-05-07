<?php

namespace Sixgweb\ConditionsAttributize;

use App;
use Event;
use Model;
use System\Classes\PluginBase;
use Backend\Classes\BackendController;
use Sixgweb\Attributize\Models\Field;
use Sixgweb\Attributize\Components\Fields;
use Sixgweb\Attributize\Models\FieldValue;
use Sixgweb\Attributize\FormWidgets\Attributize;
use Sixgweb\Attributize\FormWidgets\AttributizeFieldValue;
use Sixgweb\Conditions\Models\Condition;
use Sixgweb\Conditions\Classes\ConditionersManager;
use Sixgweb\Attributize\Behaviors\Fieldable;
use Sixgweb\Attributize\Behaviors\FieldsController;
use Sixgweb\Attributize\Behaviors\FieldsImportExportController;
use Sixgweb\ConditionsAttributize\Classes\ConditionableEventHandler;
use Sixgweb\ConditionsAttributize\Classes\FieldValueConditionerEventHandler;
use Sixgweb\ConditionsAttributize\Classes\FieldValueConditionableEventHandler;

/**
 * Plugin Information File
 */
class Plugin extends PluginBase
{

    protected $exportWidget;

    public $require = [
        'Sixgweb.Attributize',
        'Sixgweb.Conditions',
    ];
    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'ConditionsAttributize',
            'description' => 'No description provided yet...',
            'author'      => 'Sixgweb',
            'icon'        => 'icon-leaf'
        ];
    }

    /**
     * Register method, called when the plugin is first registered.
     *
     * @return void
     */
    public function register()
    {
    }

    /**
     * Boot method, called right before the request route.
     *
     * @return void
     */
    public function boot()
    {
        Event::subscribe(ConditionableEventHandler::class);
        Event::subscribe(FieldValueConditionableEventHandler::class);
        Event::subscribe(FieldValueConditionerEventHandler::class);
        $this->removeFieldValueConditioner();
        $this->removeMeetsConditionsGlobalScope();
        $this->addConditionsToCreatedFields();
        $this->extendFieldValueModel();
        $this->addSyncToolbarButton();
        $this->extendImportExport();
        $this->addChangeHandlerToFieldValueFields();
    }

    protected function addConditionsToCreatedFields()
    {
        /**
         * Little helper to automatically create conditions for new fields.
         * 
         * New field must be created outside a FieldsController controller,
         * the controller's model (FormController) must exist and is a Conditioner.
         * 
         * Special case for post('editor_level') which will be greater than 0
         * if we're in a repeater.  Repeater fields are morphed to the repeater
         * so no need for default conditions on morphed fields
         */
        Event::listen('backend.form.extendFields', function ($widget) {
            $controller = $widget->getController();
            if ($handler = $controller->getAjaxHandler()) {
                $parts = explode('::', $handler);
                $handler = end($parts);
            }

            if (
                !$controller->isClassExtendedWith(\Backend\Behaviors\FormController::class) ||
                $controller->isClassExtendedWith(FieldsController::class) ||
                $controller->methodExists('createFieldsFormWidget') ||
                !$widget->model->conditionerFields ||
                $widget->model instanceof Field ||
                !$widget->model->exists ||
                (post('nested_depth', 0) > 0) ||
                $handler != 'onLoadFieldForm'
            ) {
                return;
            }

            if (!$fields = $widget->model->conditionerFields) {
                return;
            }

            $defaultConditions = [];
            foreach ($fields as $column => $field) {
                $condition = new \stdClass;
                if (isset($field['form'])) {
                    $value = new \stdClass;
                    $value->value = $widget->model->id;
                    $value = [$value];
                } else {
                    $value = [$widget->model->id];
                }
                $condition = [
                    '_group' => str_replace('\\', '_', get_class($widget->model)),
                    '_logic' => 'inclusive',
                    'id' => $value,
                ];

                $defaultConditions[] = $condition;
            }

            Field::extend(function ($model) use ($defaultConditions) {
                $model->conditions = $defaultConditions;
            });
        });
    }

    /**
     * Adds/updates dependsOn attribute for attributize fields
     * If the form has relation fields defined,
     * they are automatically added as dependencies, allowing
     * other plugins to add the conditioner(s).
     * 
     * ConditionedFields are retrieved and any fields not matching
     * conditioned fields are hidden, allowing dependsOn to still work.
     *
     * @return void
     */
    protected function addDependsOnToAttributizeFields(): void
    {
        Event::listen('sixgweb.attributize.backend.form.extendAllFields', function ($widget, $allFields) {
            $relations = [];
            foreach ($widget->getFields() as $code => $field) {
                if (isset($field->type) && $field->type == 'relation') {
                    $relations[] = $code;
                }
            }

            if (!empty($relations)) {
                foreach ($relations as $relation) {
                    if ($field = $widget->getField($relation)) {
                        $field->changeHandler($widget->alias . '::onRefresh');
                    }
                }
            }

            $conditionerIds = Condition::where('conditionable_type', Field::class)
                ->whereIn('conditionable_id', $allFields->pluck('id')->toArray())
                ->where('conditioner_type', FieldValue::class)
                ->get()
                ->pluck('conditioner_id')
                ->toArray();

            $allFields->each(function ($field) use ($widget, $conditionerIds) {
                if ($field->fieldvalues->count()) {
                    if (empty(array_intersect($field->fieldvalues->pluck('id')->toArray(), $conditionerIds))) {
                        return;
                    }

                    $code = $field->type == 'fileupload'
                        ? $widget->model->fieldableGetColumn() . '_' . $field->code
                        : $widget->model->fieldableGetColumn() . '[' . $field->code . ']';
                    $formField = $widget->getField($code) ?? $widget->getField($field->code);

                    if ($formField) {
                        $formField->changeHandler($widget->alias . '::onRefresh');
                        $formField->containerAttributes(['data-attach-loading' => '']);
                    }
                }
            });
        });
    }

    /**
     * FieldValue conditioner is tricky because of it's ability to be used on fields and field values.
     * Exporting, importing, list setup, etc. all need to exclude the FieldValue conditioner.  Otherwise,
     * fields/field values that have a FieldValue conditioner will be hidden.
     *
     * @return void
     */
    protected function removeFieldValueConditioner()
    {
        Event::listen('backend.page.beforeDisplay', function ($controller, $action, $params) {
            $actions = [
                'index',
                'fields',
                'import',
                'export',
            ];

            if (!in_array($action, $actions)) {
                return;
            }

            ConditionersManager::instance()->excludeConditionerClass(FieldValue::class);
        });

        Attributize::extend(function () {
            ConditionersManager::instance()->excludeConditionerClass(FieldValue::class);
        });
    }

    /**
     * Remove the global meetsConditions scopes when the controller action is 'fields'.
     * 
     * Conditions are added to the filter, allowing the user to still filter values,
     * without hiding fields that don't match.
     *
     * @return void
     */
    protected function removeMeetsConditionsGlobalScope()
    {
        Attributize::extend(function ($widget) {

            if (\Backend\Classes\BackendController::$action == 'fields') {


                //Remove meetsConditions global scope so all fields are shown in the list
                Event::listen('backend.list.extendQuery', function ($listWidget, $query) {
                    $query->withoutGlobalScope('meetsConditions');
                });

                //Remove meetsConditions global scope so field editor popup is populated with model data
                Event::listen('sixgweb.attributize.getFieldModel', function ($query) {
                    $query->withoutGlobalScope('meetsConditions');
                });

                /**
                 * Would like use withoutGlobalScope but don't have access to the query
                 * in the ListStructure widget onReorder.  This overrides the meetsConditions scope
                 * with an empty callback.  Gross but works.
                 */
                Field::extend(function ($model) {
                    Event::listen('backend.list.beforeReorderStructure', function ($item) use ($model) {
                        $model->addGlobalScope('meetsConditions', function ($builder) {
                            return function () {
                            };
                        });
                    });
                });
            }
        });
    }

    /**
     * Adds dynamic method to FieldValue model to scope by fieldable type matching
     * the Attributize widget's fieldableType
     *
     * @return void
     */
    protected function extendFieldValueModel()
    {
        Event::listen('backend.form.extendFields', function ($widget) {
            $fieldableTypes = [];
            $model = $widget->model;

            //If this is an attributize widget, use the fieldableType from the _fields field.
            //Otherwise, use the model's relation definitions to get the fieldableType.
            if ($widget->context == 'attributize') {
                if ($field = $widget->getField('_fields')) {
                    $fieldableTypes[] = $field->fieldableType;
                }
            } else {
                if (
                    !method_exists($model, 'methodExists') ||
                    !$model->methodExists('getRelationDefinitions')
                ) {
                    return;
                }

                $relations = $model->getRelationDefinitions();
                if (empty($relations)) {
                    return;
                }

                foreach ($relations as $key => $definition) {
                    foreach ($definition as $name => $class) {
                        if (isset($class[0])) {
                            $fieldableTypes[] = $class[0];
                        }
                    }
                }
            }

            FieldValue::extend(function ($model) use ($fieldableTypes) {
                if ($model->methodExists('scopeMatchFieldableType')) {
                    return;
                }

                $model->addDynamicMethod('scopeMatchFieldableType', function ($query) use ($model, $fieldableTypes) {
                    $query->orWhere(function ($query) use ($fieldableTypes) {
                        $query->whereHas('field', function ($query) use ($fieldableTypes) {
                            $query->whereIn('fieldable_type', $fieldableTypes);
                        });

                        //Handles repeaters 1 level deep.  TODO: Loop posted nested_depth
                        $query->orWhereHas('field', function ($query) use ($fieldableTypes) {
                            $query->whereHas('fieldable', function ($query) use ($fieldableTypes) {
                                $query->whereIn('fieldable_type', $fieldableTypes);
                            });
                        });
                    });
                });
            });
        });
    }

    protected function addSyncToolbarButton()
    {
        Attributize::extend(function ($widget) {
            $widget->bindEvent('sixgweb.attributize.extendToolbarWidget', function ($toolbarWidget) use ($widget) {
                $path = plugins_path() . '/sixgweb/conditionsattributize/partials/_sync_toolbar_button.htm';
                $params = [
                    'listWidgetId' => $toolbarWidget->vars['listWidgetId'],
                    'syncConditionsHandler' => $widget->getEventHandler('onSyncConditions')
                ];
                $toolbarWidget->vars['dropdownItems'] .= $widget->makePartial($path, $params);
            });

            $widget->addDynamicMethod('onSyncConditions', function () use ($widget) {
                if ($ids = post('checked', null)) {
                    foreach (Field::whereIn('id', $ids)->get() as $field) {
                        $field->syncConditions();
                    }
                }
                return $widget->listWidget->onRefresh();
            });
        });
    }

    protected function extendImportExport()
    {
        FieldsImportExportController::extend(function ($controller) {

            if (BackendController::$action == 'import') {
                Event::listen('sixgweb.attributize.fieldable.getFields', function ($query) {
                    $conditioners = ConditionersManager::instance()->getConditioners();
                    $conditioners = array_filter($conditioners);
                    $withoutGlobalScope = true;

                    foreach ($conditioners as $class => $values) {
                        foreach ($values as $value) {
                            if ($value) {
                                $withoutGlobalScope = false;
                                break;
                            }
                        }
                    }

                    if ($withoutGlobalScope) {
                        $query->withoutGlobalScopes();
                    }
                });
            }

            if (BackendController::$action == 'export') {
                Event::listen('sixgweb.attributize.fieldable.getFields', function ($query) {
                    $conditioners = ConditionersManager::instance()->getConditioners();
                    $conditioners = array_filter($conditioners);
                    $withoutGlobalScope = true;

                    foreach ($conditioners as $class => $values) {
                        foreach ($values as $value) {
                            if ($value) {
                                $withoutGlobalScope = false;
                                break;
                            }
                        }
                    }

                    if ($withoutGlobalScope) {
                        $query->withoutGlobalScopes();
                    }
                });
            }
        });
    }

    /**
     * Adds onRefresh change handler to the form widget fields that have field values used as conditioner
     * 
     * @return void
     */
    protected function addChangeHandlerToFieldValueFields()
    {
        //Frontend Fields component
        Fields::extend(function ($component) {
            Event::listen('sixgweb.attributize.fieldable.afterGetFields', function (&$fields) use ($component) {
                $conditionerIds = $this->getConditionerIds($fields);
                if (empty($conditionerIds)) {
                    return;
                }

                $fields->each(function ($field) use ($component, $conditionerIds) {
                    if ($field->fieldvalues->count()) {
                        if (empty(array_intersect($field->fieldvalues->pluck('id')->toArray(), $conditionerIds))) {
                            return;
                        }
                        $config = $field->config;
                        $config['changeHandler'] = $component->alias . 'Form::onRefresh';
                        $field->config = $config;
                    }
                });
            });
        });

        //This event is only fired while in the backend
        Event::listen('sixgweb.attributize.backend.form.extendAllFields', function ($widget, $allFields) {
            $relations = [];
            foreach ($widget->getFields() as $code => $field) {
                if (isset($field->type) && $field->type == 'relation') {
                    $relations[] = $code;
                }
            }

            if (!empty($relations)) {
                foreach ($relations as $relation) {
                    if ($field = $widget->getField($relation)) {
                        $field->changeHandler($widget->alias . '::onRefresh');
                    }
                }
            }

            $conditionerIds = $this->getConditionerIds($allFields);

            if (empty($conditionerIds)) {
                return;
            }

            /**
             * By default, onRefresh will refresh the entire form.  This won't work with controllers
             * that use the form with a sidebar (secondary tab).  This workaround forces the Form widget to refresh a single field.  The form.refresh listener below will then refresh the primary and outside sections.
             */
            $widget->bindEvent('form.refreshFields', function () use ($widget, $allFields) {

                //Check if secondary tab has fields to determine if we need to refresh each section.
                if (!$widget->getTab('secondary')->hasFields()) {
                    return;
                }

                //No fields posted for update, so this is a full form refresh.
                if (empty(post('fields', []))) {
                    $key = $allFields->where('type', '!=', 'fileupload')->first()->code;
                    $key = $widget->model->fieldableGetColumn() . '[' . $key . ']';
                    request()->merge(['fields' => [$key]]);
                }
            });

            $widget->bindEvent('form.refresh', function ($result) use ($widget) {
                //Check if secondary tab has fields to determine if we need to refresh each section.
                if (!$widget->getTab('secondary')->hasFields()) {
                    return $result;
                }

                $sections = [
                    'outside',
                    'primary',
                ];

                foreach ($sections as $section) {
                    $id = '#' . $widget->getId($section . 'Container');
                    $result[$id] = $widget->render(['section' => $section, 'useContainer' => false]);
                }

                return $result;
            });

            $allFields->each(function ($field) use ($widget, $conditionerIds) {
                if ($field->fieldvalues->count()) {
                    if (empty(array_intersect($field->fieldvalues->pluck('id')->toArray(), $conditionerIds))) {
                        return;
                    }

                    $code = $field->type == 'fileupload'
                        ? $widget->model->fieldableGetColumn() . '_' . $field->code
                        : $widget->model->fieldableGetColumn() . '[' . $field->code . ']';
                    $formField = $widget->getField($code) ?? $widget->getField($field->code);

                    if ($formField) {
                        $formField->changeHandler($widget->alias . '::onRefresh');
                        $formField->containerAttributes(['data-attach-loading' => '']);
                    }
                }
            });
        });
    }

    /**
     * Get fieldvalue ids that are used as conditioners in the conditions table
     *
     * @param object $fields
     * @return array
     */
    private function getConditionerIds(\October\Rain\Database\Collection $fields): array
    {
        $conditionerIds = [];
        foreach ($fields as $field) {
            if ($field->fieldvalues->count()) {
                $conditionerIds = array_merge($conditionerIds, $field->fieldvalues->pluck('id')->toArray());
            }
        }

        if (empty($conditionerIds)) {
            return $conditionerIds;
        }

        return Condition::whereIn('conditioner_id', $conditionerIds)
            ->where('conditioner_type', FieldValue::class)
            ->get()
            ->pluck('conditioner_id')
            ->toArray();
    }
}
