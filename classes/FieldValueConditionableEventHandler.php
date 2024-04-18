<?php

namespace Sixgweb\ConditionsAttributize\Classes;

use Sixgweb\Conditions\Classes\AbstractConditionableEventHandler;

class FieldValueConditionableEventHandler extends AbstractConditionableEventHandler
{
    protected function getModelClass(): string
    {
        return \Sixgweb\Attributize\Models\FieldValue::class;
    }

    protected function getTab(): ?string
    {
        return 'Conditions';
    }

    protected function getLabel(): ?string
    {
        return 'Conditions';
    }

    protected function getComment(): ?string
    {
        return 'Conditions required for this field value to be visible';
    }

    /**
     * By default, the conditioner cannot be the same as the conditionable.
     * We allow this for FieldValue models, so one field's values can be conditioned to show/hide
     * another field's values.
     *
     * @return boolean
     */
    protected function allowSelfAsConditioner(): bool
    {
        return true;
    }
}
