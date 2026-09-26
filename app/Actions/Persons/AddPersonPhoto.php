<?php

namespace App\Actions\Persons;

use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Persons\PhotoSanitiser;
use Illuminate\Http\UploadedFile;

/**
 * Adds a photo to a person; it becomes the current photo (previous photos
 * are kept). Only a re-encoded copy without metadata is stored
 * (PhotoSanitiser), on the private evidence disk, under a neutral file
 * name: the uploaded file name is never kept. Thumbnail and preview
 * conversions are generated on the queue.
 */
final class AddPersonPhoto
{
    public function __construct(private readonly PhotoSanitiser $sanitiser) {}

    public function __invoke(Person $person, UploadedFile $upload, User $actor): Media
    {
        $clean = $this->sanitiser->sanitise((string) $upload->getRealPath());

        try {
            /** @var Media $media */
            $media = $person->addMedia($clean['path'])
                ->usingName('photo')
                ->usingFileName('photo.'.$clean['extension'])
                ->withCustomProperties(['uploaded_by' => $actor->ulid])
                ->toMediaCollection(Person::PHOTOS);
        } finally {
            if (is_file($clean['path'])) {
                @unlink($clean['path']);
            }
        }

        AuditLog::event('photo_added', 'Photo added', $person, ['photo' => $media->ulid], $actor);

        return $media;
    }
}
