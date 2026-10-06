<?php

namespace App\Http\Requests\LocalGovernment;

use App\Enums\UploadKind;
use App\Rules\MalwareFree;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload a community CSV for preview. The LGA is checked against the
 * user's jurisdiction and import permission in the controller.
 */
class CommunityImportPreviewRequest extends FormRequest
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
            'file' => ['bail', 'required', 'file', 'max:5120', new MalwareFree(UploadKind::CommunityCsv), 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'],
        ];
    }
}
