<?php

namespace App\Http\Requests\LocalGovernment;

use App\Support\LocalGovernment\CommunityCsv;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirm a previewed import: the previewed rows (re-validated and
 * re-classified by the server) and the lines of duplicates to import
 * anyway. Authorisation is in the controller.
 */
class CommunityImportRequest extends FormRequest
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
            'lga' => ['required', 'string', 'size:26'],
            'rows' => ['required', 'array', 'min:1', 'max:'.CommunityCsv::MAX_ROWS],
            'rows.*' => ['array'],
            'rows.*.line' => ['required', 'integer'],
            'include' => ['present', 'array'],
            'include.*' => ['integer'],
        ];
    }

    /**
     * The rows as sent. validated() would keep only the keys with rules
     * (`line`); every value is validated by ClassifyCommunityRows instead.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $this->validated();

        /** @var list<array<string, mixed>> */
        return array_values(array_filter((array) $this->input('rows'), 'is_array'));
    }

    /**
     * @return list<int>
     */
    public function includedLines(): array
    {
        return array_values(array_map('intval', $this->validated('include')));
    }
}
