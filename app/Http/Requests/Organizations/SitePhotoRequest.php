<?php

namespace App\Http\Requests\Organizations;

use App\Enums\UploadKind;
use App\Rules\ImageFile;
use App\Rules\MalwareFree;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A site photo (Phase 11D): on upload, the file (JPEG, PNG or WebP by
 * content, at most 15 MB, scanned for malware) with an optional caption
 * and date; on edit, the caption and date only. Authorisation is in the
 * controller.
 */
class SitePhotoRequest extends FormRequest
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
            'caption' => ['nullable', 'string', 'max:200'],
            'taken_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];

        if ($this->isMethod('post')) {
            $rules['photo'] = ['bail', 'required', 'file', 'max:'.self::maxKb(), new MalwareFree(UploadKind::SitePhoto), 'mimetypes:image/jpeg,image/png,image/webp', new ImageFile('photo')];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.mimetypes' => 'The photo must be a JPEG, PNG or WebP image.',
            'photo.max' => 'The photo may be at most '.round(self::maxKb() / 1024).' MB.',
            'taken_on.before_or_equal' => 'The date taken cannot be in the future.',
        ];
    }

    public function caption(): ?string
    {
        $caption = trim((string) $this->validated('caption'));

        return $caption === '' ? null : $caption;
    }

    public function takenOn(): ?CarbonImmutable
    {
        $date = $this->validated('taken_on');

        return is_string($date) && $date !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $date) ?: null : null;
    }

    private static function maxKb(): int
    {
        return (int) config('buffalo.organizations.site_photo_max_kb');
    }
}
