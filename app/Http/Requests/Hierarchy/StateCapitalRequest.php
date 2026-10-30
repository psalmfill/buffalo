<?php

namespace App\Http\Requests\Hierarchy;

/** A state's capital (states are otherwise fixed). */
class StateCapitalRequest extends HierarchyChangeRequest
{
    protected function fields(): array
    {
        return [
            'capital' => ['nullable', 'string', 'max:255'],
        ];
    }
}
