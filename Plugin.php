<?php

namespace Sixgweb\ConditionsAttributize;

use App;
use Event;
use System\Classes\PluginBase;
use Backend\Classes\BackendController;
use Sixgweb\Attributize\Models\Field;
use Sixgweb\Attributize\Models\FieldValue;
use Sixgweb\Attributize\FormWidgets\Attributize;
use Sixgweb\Conditions\Classes\ConditionersManager;
use Sixgweb\Attributize\Behaviors\FieldsController;
use Sixgweb\Attributize\Behaviors\FieldsImportExportController;
use Sixgweb\Attributize\Components\Fields;
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
        $this->removeMeetsConditionsGlobalScope();
        $this->addConditionsToCreatedFields();
        $this->addDependsOnToAttributizeFields();
        $this->extendPreview();
        $this->extendFieldValueModel();
        $this->addSyncToolbarButton();
        $this->extendImportExport();
        $this->extendFieldsComponent();
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

            /*if (!empty($defaultConditions)) {
                Flash::info($field['label'] . ' Condition Automatically Added');
            }*/

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

            if (empty($relations)) {
                return;
            }

            $allFields->each(function ($field) use ($widget, $relations) {
                $code = $field->type == 'fileupload'
                    ? $widget->model->fieldableGetColumn() . '_' . $field->code
                    : $widget->model->fieldableGetColumn() . '[' . $field->code . ']';
                $formField = $widget->getField($code);
                if (!$formField) {
                    $formField = $widget->getField($field->code);
                }
                if (!$formField) {
                    return;
                }
                $depends = [];
                if (isset($formField->config['dependsOn']) && $formField->config['dependsOn']) {
                    $depends = is_array($formField->config['dependsOn'])
                        ? $formField->config['dependsOn']
                        : [$formField->config['dependsOn']];
                }
                $depends = array_merge($depends, $relations);
                $formField->dependsOn($depends);
            });
        });
    }

    /**
     * Remove the global meetsConditions scopes when the controller action is 'fields'.
     * Conditions are added to the filter, allowing the user to still filter values,
     * without hiding fields that don't match.
     *
     * @return void
     */
    protected function removeMeetsConditionsGlobalScope()
    {
        Attributize::extend(function ($widget) {
            if (\Backend\Classes\BackendController::$action == 'fields') {
                Event::listen('backend.list.extendQuery', function ($listWidget, $query) {
                    $query->withoutGlobalScope('meetsConditions');
                });
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
            } else {
                $fieldValues = FieldValue::select('id')
                    ->whereHas('field', function ($query) use ($widget) {
                        $query->where('fieldable_type', $widget->getConfig('fieldableType'));
                    })->get()->pluck('id')->toArray();
                $conditionsManager = ConditionersManager::instance();
                $conditionsManager->addConditioner([FieldValue::class => $fieldValues]);
            }
        });
    }

    /**
     * Since the createPreview method is using FieldsHelper to generate the preview fields in attributize
     * form widget, the filterWidget never calls the model.filter.filterScopes event, listened for in 
     * AbstractConditionableEventHandler.  This workaround listens to the createPreview event 
     * and performs the same function
     *
     * @return void
     */
    protected function extendPreview()
    {
        Event::listen('sixgweb.attributize.createPreview', function ($query, $filterWidget) {
            /**
             * Remove the meets conditions global scope when in the Fields action.  Otherwise,
             * some fields that have conditions will not be visible in the interface (e.g. Forms->Entry Fields)
             **/
            if ($filterWidget->getController()->getAction() == 'fields') {
                $query->withoutGlobalScope('meetsConditions');
            }

            $conditionersManager = ConditionersManager::instance();
            foreach ($filterWidget->getScopes() as $scope) {
                if ($scope->modelScope && $scope->modelScope == 'meetsConditions') {
                    $name = $scope->scopeName;
                    $class = str_replace('_', '\\', $name);
                    if ($scope->value) {
                        $conditionersManager->addConditioner([$class => $scope->value]);
                    } else {
                        $conditionersManager->removeConditioner($class);
                    }
                }
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
        Attributize::extend(function ($widget) {
            FieldValue::extend(function ($model) use ($widget) {
                //Don't add dynamic method when Attributize is in a repeater editor
                if ($widget->isRepeater) {
                    return;
                }

                $model->addDynamicMethod('scopeMatchFieldableType', function ($query) use ($widget) {
                    if ($widget->getFieldModel()->exists) {
                        $query->where('field_id', '!=', $widget->getFieldModel()->id);
                    }
                    $query->where(function ($query) use ($widget) {
                        $query->whereHas('field', function ($query) use ($widget) {

                            $query->where('fieldable_type', $widget->fieldableType);
                        });

                        //Handles repeaters 1 level deep.  TODO: Loop posted nested_depth
                        $query->orWhereHas('field', function ($query) use ($widget) {
                            $query->whereHas('fieldable', function ($query) use ($widget) {
                                $query->where('fieldable_type', $widget->fieldableType);
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
     * Extend fields component for frontend
     *
     * @return void
     */
    protected function extendFieldsComponent(): void
    {
        /**
         * Trigger form refresh when any fieldvalues are changed on the component form.  This allows
         * conditions to be applied and the form to add/remove fields/field values that utilize these
         * potential conditions.
         */
        Fields::extend(function ($component) {
            Event::listen('sixgweb.attributize.fieldable.afterGetFields', function (&$fields) use ($component) {
                foreach ($fields as $field) {
                    if ($field->fieldvalues->count()) {
                        $config = $field->config;
                        $config['changeHandler'] = $component->alias . 'Form::onRefresh';
                        $field->config = $config;
                    }
                }
            });
        });
    }
}
