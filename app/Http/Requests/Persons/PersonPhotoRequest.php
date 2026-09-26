<?php

namespace App\Http\Requests\Persons;

use App\Rules\PersonPhoto;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new photo for a person: JPEG, PNG or WebP (by content), at most 10 MB.
 * Authorisation is in the controller.
 */
class PersonPhotoRequest extends FormRequest
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
            'photo' => ['required', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp', new PersonPhoto],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.mimetypes' => 'The photo must be a JPEG, PNG or WebP image.',
            'photo.max' => 'The photo may be at most 10 MB.',
        ];
    }
}
