<?php

namespace App\Http\Presenters;

use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\RecordAccessLog;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\OfficerNames;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Evidence for the browser (Phase 5B): ULIDs only, never file paths or
 * URLs from storage; files are fetched through the evidence routes.
 */
final class EvidencePresenter
{
    /**
     * @param  Collection<int, IncidentEvidence>  $items  loaded withCoordinates()
     * @return list<array<string, mixed>>
     */
    public static function list(Collection $items, User $viewer): array
    {
        $names = OfficerNames::for($items->map(fn (IncidentEvidence $e) => $e->uploaded_by));

        return array_values($items->map(fn (IncidentEvidence $e) => self::row($e, $viewer, $names->get($e->uploaded_by)))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(IncidentEvidence $evidence, User $viewer, ?string $uploader): array
    {
        $media = $evidence->original();
        $exif = $evidence->exif_summary ?? [];
        $clean = $evidence->isScannedClean();

        return [
            'ulid' => $evidence->ulid,
            'title' => $evidence->title,
            // Moved from a duplicate (Phase 10B): "From BUF/…".
            'consolidatedFrom' => $evidence->consolidated_from_incident_id === null ? null
                : DB::table('incidents')->where('id', $evidence->consolidated_from_incident_id)->value('reference_no'),
            'description' => $evidence->description,
            'type' => ['value' => $evidence->evidence_type->value, 'label' => $evidence->evidence_type->label()],
            'mime_type' => $evidence->mime_type,
            'original_filename' => $evidence->original_filename,
            'size_bytes' => $evidence->size_bytes,
            'sha256' => $evidence->sha256,
            'captured_at' => $evidence->captured_at?->toIso8601String(),
            'captured_at_local' => $evidence->captured_at?->setTimezone((string) config('app.timezone'))->format('Y-m-d\TH:i'),
            'latitude' => $evidence->latitude === null ? null : (float) $evidence->latitude,
            'longitude' => $evidence->longitude === null ? null : (float) $evidence->longitude,
            'camera' => trim(implode(' ', array_filter([$exif['make'] ?? null, $exif['model'] ?? null], 'is_string'))) ?: null,
            'exif_time' => is_string($exif['datetime_original'] ?? null) ? $exif['datetime_original'] : null,
            'classification' => ['value' => $evidence->classificationLevel()->value, 'label' => $evidence->classificationLevel()->label()],
            'processing' => ! $evidence->isProcessed(),
            // Only clean files are served (Phase 8B.4).
            'scan' => ['value' => $evidence->scan_status->value, 'label' => $evidence->scan_status->label()],
            'has_thumb' => $clean && $evidence->evidence_type === EvidenceType::Image && $media?->hasGeneratedConversion('thumb') === true,
            'has_preview' => $clean && $evidence->evidence_type === EvidenceType::Image && $media?->hasGeneratedConversion('preview') === true,
            'playable' => $clean && in_array($evidence->evidence_type, [EvidenceType::Video, EvidenceType::Audio], true),
            'uploaded_by' => $uploader,
            'uploaded_at' => $evidence->created_at?->toIso8601String(),
            'can' => [
                'update' => $viewer->can('update', $evidence),
                'download' => $clean && $viewer->can('download', $evidence),
                'verify' => $viewer->can('verify', $evidence),
                'delete' => $viewer->can('delete', $evidence),
            ],
        ];
    }

    /**
     * Classification levels the viewer may give evidence on $incident: at
     * least the incident's, within their clearance.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function levels(Incident $incident, User $viewer): array
    {
        $gate = app(ClassificationGate::class);

        return array_values(array_map(
            fn (Classification $c) => ['value' => $c->value, 'label' => $c->label()],
            array_filter(
                Classification::cases(),
                fn (Classification $c) => $c->rank() >= $incident->classificationLevel()->rank() && $gate->canAssign($viewer, $c),
            ),
        ));
    }

    /**
     * The chain of custody, oldest first: upload, metadata and
     * classification changes, integrity checks, removal (activity log) and
     * every view and download (record_access_logs).
     *
     * @return list<array{at: string|null, who: string|null, event: string, label: string, detail: string|null, ip: string|null}>
     */
    public static function custody(IncidentEvidence $evidence): array
    {
        $activities = Activity::query()
            ->where('subject_type', $evidence->getMorphClass())
            ->where('subject_id', $evidence->id)
            ->orderBy('id')
            ->get();
        $reads = RecordAccessLog::query()
            ->where('subject_type', $evidence->getMorphClass())
            ->where('subject_id', $evidence->id)
            ->orderBy('id')
            ->get();

        $userType = (new User)->getMorphClass();
        $names = OfficerNames::for([
            $evidence->uploaded_by,
            ...$activities->map(fn (Activity $a) => $a->causer_type === $userType ? (int) $a->causer_id : null),
            ...$reads->map(fn (RecordAccessLog $r) => $r->user_id),
        ]);

        $entries = [];

        foreach ($activities as $activity) {
            $entry = self::activityEntry($activity);

            if ($entry === null) {
                continue;
            }

            $properties = $activity->properties instanceof Collection ? $activity->properties->all() : [];
            $entries[] = [
                'at' => $activity->created_at?->toIso8601String(),
                // The uploader is recorded on the item itself.
                'who' => $activity->event === 'created'
                    ? $names->get($evidence->uploaded_by)
                    : ($activity->causer_type === $userType ? $names->get((int) $activity->causer_id) : null),
                'event' => $entry[0],
                'label' => $entry[1],
                'detail' => $entry[2],
                'ip' => is_string($properties['ip_address'] ?? null) ? $properties['ip_address'] : null,
            ];
        }

        foreach ($reads as $read) {
            $download = $read->action === AccessAction::Download;
            $entries[] = [
                'at' => $read->created_at?->toIso8601String(),
                'who' => $read->user_id === null ? null : $names->get($read->user_id),
                'event' => $download ? 'download' : 'view',
                'label' => $download ? 'Original downloaded' : 'Viewed',
                'detail' => $download ? null : match ($read->context) {
                    'preview' => 'Image preview',
                    'stream' => 'Played',
                    'incident_page' => 'Listed on the incident page',
                    default => $read->context,
                },
                'ip' => $read->ip_address,
            ];
        }

        usort($entries, fn (array $a, array $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $entries;
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}|null event, label, detail
     */
    private static function activityEntry(Activity $activity): ?array
    {
        $properties = $activity->properties instanceof Collection ? $activity->properties->all() : [];
        $changes = $activity->attribute_changes instanceof Collection ? $activity->attribute_changes->all() : [];
        $new = is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [];

        return match ($activity->event) {
            'created' => ['upload', 'Uploaded', 'SHA-256 '.($new['sha256'] ?? '?').' · '.number_format((int) ($new['size_bytes'] ?? 0)).' bytes · '.($new['mime_type'] ?? '')],
            'updated' => self::edited($new),
            'classification_changed' => ['classification', 'Classification changed', ucfirst((string) ($properties['old'] ?? '?')).' → '.ucfirst((string) ($properties['new'] ?? '?'))],
            'location_changed' => ['metadata', 'Capture location changed', null],
            'integrity_verified' => ['integrity', 'Integrity verified', 'The stored file matches its recorded hash.'],
            'integrity_failure' => ['integrity_failure', 'Integrity check failed', 'The stored file does not match its recorded hash ('.($properties['context'] ?? 'check').').'],
            'consolidated' => ['consolidated', 'Consolidated', 'Moved from '.($properties['from'] ?? '?').' to '.($properties['to'] ?? '?').' (file, hash and history unchanged).'],
            'evidence_deleted' => ['deleted', 'Removed from the incident', is_string($properties['reason'] ?? null) ? 'Reason: '.$properties['reason'] : null],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $new
     * @return array{0: string, 1: string, 2: string|null}|null
     */
    private static function edited(array $new): ?array
    {
        $labels = ['title' => 'title', 'description' => 'description', 'captured_at' => 'capture time'];
        $fields = array_values(array_intersect_key($labels, $new));

        return $fields === [] ? null : ['metadata', 'Details edited', 'Changed: '.implode(', ', $fields)];
    }
}
