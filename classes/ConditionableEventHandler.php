<?php

namespace Sixgweb\ConditionsAttributize\Classes;

use Sixgweb\Conditions\Classes\AbstractConditionableEventHandler;

class ConditionableEventHandler extends AbstractConditionableEventHandler
{
    protected function getModelClass(): string
    {
        return \Sixgweb\Attributize\Models\Field::class;
    }

    protected function getTab(): ?string
    {
        return 'sixgweb.attributize::lang.field.visibility';
    }

    protected function getLabel(): ?string
    {
        return 'Conditions';
    }

    protected function getComment(): ?string
    {
        return 'Conditions required for this field to be visible';
    }
}
