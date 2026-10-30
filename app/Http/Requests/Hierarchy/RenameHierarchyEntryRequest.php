<?php

namespace App\Http\Requests\Hierarchy;

/** Renaming a district, LGA or ward (an LGA also its headquarters). Never the code. */
class RenameHierarchyEntryRequest extends HierarchyChangeRequest
{
    protected function fields(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'headquarters' => ['nullable', 'string', 'max:255'],
        ];
    }
}
