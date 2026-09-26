<?php

namespace App\Http\Requests\PersonsOfInterest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mark a POI reviewed: a short comment, and optionally the next review
 * date (default: BUFFALO_POI_REVIEW_MONTHS months ahead).
 */
class PoiReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'min:5', 'max:1000'],
            'next_review' => ['nullable', 'date_format:Y-m-d', 'after:today', 'before:+5 years'],
        ];
    }
}
