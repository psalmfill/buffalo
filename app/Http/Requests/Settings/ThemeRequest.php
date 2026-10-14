<?php

namespace App\Http\Requests\Settings;

use App\Support\Theme\Theme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The theme colours (Phase 11A.3): every colour as #rrggbb, and each must
 * reach its WCAG AA contrast against the card background of its mode. A
 * failure names the measured ratio and the nearest passing colour.
 */
class ThemeRequest extends FormRequest
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
        $rules = [
            'brand' => ['required', 'array:primary,accent'],
            'chart' => ['required', 'array:light,dark'],
            'chart.light' => ['required', 'array', 'size:'.Theme::PALETTE_SIZE],
            'chart.dark' => ['required', 'array', 'size:'.Theme::PALETTE_SIZE],
            'kpi' => ['required', 'array:'.implode(',', Theme::KPI_TYPES)],
        ];

        foreach (array_keys(Theme::fields()) as $path) {
            $rules[$path] = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['*.regex' => 'Use a colour in the form #rrggbb.', '*.*.regex' => 'Use a colour in the form #rrggbb.', '*.*.*.regex' => 'Use a colour in the form #rrggbb.'];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (Theme::contrastErrors($this->all()) as $path => $message) {
                $validator->errors()->add($path, $message);
            }
        }];
    }
}
