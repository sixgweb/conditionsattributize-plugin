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
}
