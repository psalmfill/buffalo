<?php

namespace App\Http\Requests\Hierarchy;

/**
 * Creating a district, LGA or ward: code and name (the action checks the
 * format and uniqueness); an LGA also takes its district and headquarters.
 */
class StoreHierarchyEntryRequest extends HierarchyChangeRequest
{
    protected function fields(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'headquarters' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:26'],
        ];
    }
}
