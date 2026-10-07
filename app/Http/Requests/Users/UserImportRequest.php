<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use App\Support\Users\UserCsv;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmation of a bulk import: the previewed rows, re-sent and validated
 * again by ImportUsers.
 */
class UserImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['rows' => ['required', 'array', 'min:1', 'max:'.UserCsv::MAX_ROWS], 'rows.*.line' => ['required', 'integer', 'min:1']];

        foreach (UserCsv::HEADER as $column) {
            $rules["rows.*.{$column}"] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /**
     * @return list<array<string, string|int>>
     */
    public function rows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values((array) $this->input('rows', []));

        return array_map(function (array $row): array {
            $clean = ['line' => (int) ($row['line'] ?? 0)];
            foreach (UserCsv::HEADER as $column) {
                $clean[$column] = trim((string) ($row[$column] ?? ''));
            }

            return $clean;
        }, $rows);
    }
}
