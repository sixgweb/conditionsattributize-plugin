<?php

namespace Sixgweb\ConditionsAttributize;

use Event;
use System\Classes\PluginBase;
use Sixgweb\Attributize\Models\Field;
use Sixgweb\Conditions\Behaviors\Conditioner;
use Sixgweb\Conditions\Behaviors\Conditionable;
use Sixgweb\Attributize\FormWidgets\Attributize;
use Sixgweb\Conditions\Classes\ConditionsManager;
use Sixgweb\Attributize\Behaviors\FieldsController;
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
        $this->extendFormWidget();
        $this->addConditionsToCreatedFields();
        $this->extendPreview();
        $this->addSyncToolbarButton();
    }

    protected function addConditionsToCreatedFields()
    {
        /**
         * Little helper to automatically create conditions for new fields.
         * 
         * New field must be created outside a FieldsController controller,
         * the controller's model (FormController) must exist and is a Conditioner.
         * 
         * Special case for post('nested_depth') which will be greater than 0
         * if we're in a repeater.  Repeater fields are morphed to the repeater
         * so no need for default conditions on morphed fields
         */
        Event::listen('backend.form.extendFields', function ($widget) {
            $controller = $widget->getController();
            if (
                !$controller->isClassExtendedWith(\Backend\Behaviors\FormController::class) ||
                $controller->isClassExtendedWith(FieldsController::class) ||
                $controller->methodExists('createFieldsFormWidget') ||
                !$widget->model->isClassExtendedWith(Conditioner::class) ||
                $widget->model instanceof Field ||
                !$widget->model->exists ||
                (post('nested_depth', 0) > 0)
            ) {
                return;
            }

            if (!$fields = $widget->model->getConditionerFields()) {
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
     * Remove the global meetsConditions scopes when the controller action is 'fields'.
     * Conditions are added to the filter, allowing the user to still filter values,
     * without hiding fields that don't match.
     *
     * @return void
     */
    protected function extendFormWidget()
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
     * Extend the filter widget, hiding the scope for the controller's model
     * if the controller does not use the FieldsController behavior.  This condition
     * will be auto applied in extendFormWidget above and should not be user selectable 
     *
     * @return void
     */
    protected function extendFilterWidget()
    {
        Event::listen('backend.filter.extendScopes', function ($widget) {
            if (!$widget->model->isClassExtendedWith(Conditionable::class)) {
                return;
            }

            if (!$widget->getController()->isClassExtendedWith(FieldsController::class)) {
                if ($model = $widget->getController()->asExtension('FormController')->formGetModel()) {
                    $scope = str_replace('\\', '_', get_class($model));
                    $widget->removeScope($scope);
                }
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
            $conditionsManager = ConditionsManager::instance();
            foreach ($filterWidget->getScopes() as $scope) {
                if ($scope->modelScope && $scope->modelScope == 'meetsConditions') {
                    $name = $scope->scopeName;
                    $class = str_replace('_', '\\', $name);
                    if ($scope->value) {
                        $conditionsManager->addConditioner([$class => $scope->value]);
                    } else {
                        $conditionsManager->removeConditioner($class);
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
}
