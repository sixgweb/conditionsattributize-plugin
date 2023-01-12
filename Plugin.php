<?php

namespace Sixgweb\ConditionsAttributize;

use App;
use Event;
use Flash;
use System\Classes\PluginBase;
use Backend\Classes\BackendController;
use Sixgweb\Attributize\Models\Field;
use Sixgweb\Attributize\Behaviors\Fieldable;
use Sixgweb\Attributize\FormWidgets\Attributize;
use Sixgweb\Conditions\Classes\ConditionersManager;
use Sixgweb\Attributize\Behaviors\FieldsController;
use Sixgweb\Attributize\Behaviors\FieldsImportExportController;
use Sixgweb\ConditionsAttributize\Classes\ConditionableEventHandler;

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
        $this->removeMeetsConditionsGlobalScope();
        $this->addConditionsToCreatedFields();
        $this->addDependsOnToAttributizeFields();
        $this->extendPreview();
        $this->addSyncToolbarButton();
        $this->extendImportExport();
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

            if (!empty($defaultConditions)) {
                Flash::info($field['label'] . ' Condition Automatically Added');
            }

            Field::extend(function ($model) use ($defaultConditions) {
                $model->conditions = $defaultConditions;
            });
        });
    }

    /**
     * Adds/updates dependsOn attribute for attributizefields
     * field type.  If the form has relation fields defined,
     * they are automatically added as dependencies, allowing
     * other plugins to add the conditioner(s)
     *
     * @return void
     */
    protected function addDependsOnToAttributizeFields(): void
    {
        Event::listen('backend.form.extendFields', function ($widget) {

            if (
                !App::runningInBackend() ||
                !method_exists($widget->model, 'extendableConstruct') ||
                !$widget->model->isClassExtendedWith(Fieldable::class)
            ) {
                return;
            }

            $attributizeFields = null;
            $relations = [];
            foreach ($widget->fields as $code => $field) {
                if (isset($field['type'])) {
                    if ($field['type'] == 'attributizefields') {
                        $attributizeFields = $code;
                    }

                    if ($field['type'] == 'relation') {
                        $relations[] = $code;
                    }
                }
            }

            if (!$attributizeFields || empty($relations)) {
                return;
            }

            //Get the field and update the dependsOn attribute
            $field = $widget->getField($attributizeFields);
            $depends = isset($field->dependsOn) && is_array($field->dependsOn)
                ? $field->dependsOn
                : [];
            $depends = $depends + $relations;
            $field->dependsOn($depends);
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
                    $query->withoutGlobalScopes(['meetsConditions']);
                });
            }
        });
    }

    /**
     * Since the createPreview method is using FieldsHelper to generate the preview fields in attributize
     * form widget, the filterWidget never calls the model.filter.filterScopes event, listened for in 
     * AbstractConditioanbleEventHandler.  This workaround listens to the createPreview event 
     * and performs the same function
     *
     * @return void
     */
    protected function extendPreview()
    {
        Event::listen('sixgweb.attributize.createPreview', function ($query, $filterWidget) {
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
                Event::listen('sixgweb.attributize.getFieldableFields', function ($query) {
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
                Event::listen('sixgweb.attributize.getFieldableFields', function ($query) {
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
}
