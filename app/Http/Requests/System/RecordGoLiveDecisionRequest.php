<?php

namespace App\Http\Requests\System;

use App\Enums\GoLiveDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordGoLiveDecisionRequest extends FormRequest
{
    /** Super admins only (System health), before validation. */
    public function authorize(): bool
    {
        return $this->user()?->can('viewSystemHealth') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(GoLiveDecision::class)],
            'outcome' => ['required', 'string', 'max:50'],
            'details' => ['required', 'string', 'min:10', 'max:5000'],
            'client_representative' => ['required', 'string', 'max:255'],
            'decided_on' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
