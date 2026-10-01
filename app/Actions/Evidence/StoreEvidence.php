<?php

namespace App\Actions\Evidence;

use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Jobs\ProcessEvidenceJob;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\User;
use App\Support\Evidence\ExifReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stores one uploaded evidence file on an incident (Phase 5B).
 *
 * - The SHA-256 of the uploaded bytes is computed first, server-side, and
 *   recorded; the original is then stored unaltered (medialibrary copies
 *   the file; its stored name is generated, never the uploader's name).
 * - EXIF (images) is read once: camera, original time and GPS, which
 *   pre-fill captured_at and captured_location (editable later).
 * - Small files are verified at once. Larger ones (buffalo.evidence.
 *   queue_above_mb) are verified on the queue; the item shows
 *   "processing" (processed_at null) until then. Image previews are always
 *   generated on the queue.
 *
 * @phpstan-type Detected array{type: EvidenceType, mime: string, extension: string}
 */
final class StoreEvidence
{
    public function __construct(
        private readonly ExifReader $exif,
        private readonly VerifyEvidenceIntegrity $verify,
    ) {}

    /**
     * @param  Detected  $detected
     * @param  array{title: string, description: string|null, classification: Classification}  $data
     */
    public function __invoke(Incident $incident, UploadedFile $file, array $detected, array $data, User $actor): IncidentEvidence
    {
        $path = (string) $file->getRealPath();
        $sha256 = hash_file('sha256', $path);

        if ($sha256 === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        $size = (int) filesize($path);
        $queued = $size > (int) config('buffalo.evidence.queue_above_mb') * 1024 * 1024;
        $exif = $detected['type'] === EvidenceType::Image ? $this->exif->read($path) : null;

        $evidence = DB::transaction(function () use ($incident, $file, $path, $detected, $data, $actor, $sha256, $size, $exif): IncidentEvidence {
            $evidence = new IncidentEvidence;
            $evidence->forceFill([
                'incident_id' => $incident->id,
                'state_id' => $incident->state_id,
                'lga_id' => $incident->lga_id,
                'title' => $data['title'],
                'description' => $data['description'],
                'evidence_type' => $detected['type'],
                'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $detected['mime'],
                'size_bytes' => $size,
                'sha256' => $sha256,
                'captured_at' => $exif['captured_at'] ?? null,
                'captured_location' => Incident::pointFrom($exif['latitude'] ?? null, $exif['longitude'] ?? null),
                'exif_summary' => $exif['summary'] ?? null,
                'classification' => $data['classification'],
                'uploaded_by' => $actor->id,
                'created_by' => $actor->id,
            ])->save();

            $evidence->addMedia($path)
                ->preservingOriginal()
                ->usingName('original')
                ->usingFileName('original.'.$detected['extension'])
                ->toMediaCollection(IncidentEvidence::ORIGINAL);

            return $evidence;
        });

        if ($queued) {
            ProcessEvidenceJob::dispatch($evidence->id, $actor->id);
        } elseif (($this->verify)($evidence, $actor, 'upload')) {
            $evidence->forceFill(['processed_at' => now()])->saveQuietly();
        }

        return $evidence;
    }
}
