<?php

namespace App\Http\Requests\Users;

use App\Enums\UploadKind;
use App\Models\User;
use App\Rules\MalwareFree;
use Illuminate\Foundation\Http\FormRequest;

class UserImportPreviewRequest extends FormRequest
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
        return [
            'file' => ['bail', 'required', 'file', 'max:2048', new MalwareFree(UploadKind::UserCsv), 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'],
        ];
    }
}
