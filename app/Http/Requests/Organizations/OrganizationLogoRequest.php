<?php

namespace App\Http\Requests\Organizations;

use App\Enums\UploadKind;
use App\Rules\ImageFile;
use App\Rules\MalwareFree;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new organization logo (Phase 11D): PNG, JPEG or WebP by content (never
 * SVG), at most 2 MB, scanned for malware. Authorisation is in the
 * controller.
 */
class OrganizationLogoRequest extends FormRequest
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
            'logo' => ['bail', 'required', 'file', 'max:'.self::maxKb(), new MalwareFree(UploadKind::OrganizationLogo), 'mimetypes:image/jpeg,image/png,image/webp', new ImageFile('logo')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.mimetypes' => 'The logo must be a JPEG, PNG or WebP image.',
            'logo.max' => 'The logo may be at most '.round(self::maxKb() / 1024, 1).' MB.',
        ];
    }

    private static function maxKb(): int
    {
        return (int) config('buffalo.organizations.logo_max_kb');
    }
}
