<?php

namespace App\Http\Presenters;

use App\Enums\Classification;
use App\Enums\TaskingPriority;
use App\Enums\TaskingRecipientStatus;
use App\Enums\TaskingStatus;
use App\Enums\TaskingTargetMode;
use App\Models\Tasking;
use App\Models\TaskingAttachment;
use App\Models\TaskingNote;
use App\Models\TaskingRecipient;
use App\Models\TaskingResponse;
use App\Models\User;
use App\Support\Jurisdiction\OfficerNames;
use App\Support\RichText\RichText;
use App\Support\Taskings\TaskingProgress;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Taskings for the browser (Phase 11H): ULIDs and labels, never ids. LGA
 * chiefs are given only their own LGA's row: never the recipients list,
 * the target mode, the progress counts or other LGAs' responses.
 *
 * @phpstan-import-type Progress from TaskingProgress
 */
final class TaskingPresenter
{
    /**
     * A list row. $progress for viewers who see every recipient; $mine for
     * an LGA chief (their own row).
     *
     * @param  Progress|null  $progress
     * @return array<string, mixed>
     */
    public static function row(Tasking $tasking, ?array $progress, ?TaskingRecipient $mine = null, bool $forLga = false): array
    {
        $forLga = $forLga || $mine !== null;

        $now = CarbonImmutable::now();

        return [
            'ulid' => $tasking->ulid,
            'reference' => $tasking->reference_no,
            'title' => $tasking->displayTitle(),
            'has_title' => $tasking->title !== null && trim($tasking->title) !== '',
            'first_line' => Str::limit(trim((string) $tasking->body_text), 140),
            'priority' => self::option($tasking->priority),
            'status' => self::option($tasking->status),
            // Would tell an LGA chief that other LGAs received it.
            'target_mode' => ! $forLga && $tasking->target_mode !== null ? self::option($tasking->target_mode) : null,
            'state' => $tasking->relationLoaded('state') ? $tasking->state->name : null,
            'classification' => self::option($tasking->classificationLevel()),
            'sent_at' => $tasking->sent_at?->toIso8601String(),
            'created_at' => $tasking->created_at?->toIso8601String(),
            'updated_at' => $tasking->updated_at?->toIso8601String(),
            'response_due_at' => $tasking->response_due_at?->toIso8601String(),
            'progress' => $progress === null ? null : [...$progress, 'line' => TaskingProgress::line($progress)],
            'mine' => $mine === null ? null : [
                'ulid' => $mine->ulid,
                'status' => self::option($mine->status),
                'unread' => $mine->status === TaskingRecipientStatus::Delivered,
                'overdue' => $mine->isOverdue($tasking, $now) && $tasking->isSent(),
            ],
        ];
    }

    /**
     * @param  bool  $forLga  an LGA chief's view (no target mode)
     * @return array<string, mixed>
     */
    public static function detail(Tasking $tasking, bool $forLga = false): array
    {
        $names = OfficerNames::for([$tasking->sent_by, $tasking->recalled_by, $tasking->created_by]);

        return [
            ...self::row($tasking, null, null, $forLga),
            'title_input' => $tasking->title,
            'body' => RichText::display($tasking->body),
            'body_raw' => $tasking->body,
            'response_due_local' => $tasking->response_due_at?->setTimezone((string) config('app.timezone'))->format('Y-m-d\TH:i'),
            'sent_by' => $names->get($tasking->sent_by),
            'created_by' => $names->get($tasking->created_by),
            'recall' => $tasking->status === TaskingStatus::Recalled ? [
                'by' => $names->get($tasking->recalled_by),
                'at' => $tasking->recalled_at?->toIso8601String(),
                'reason' => $tasking->recall_reason,
            ] : null,
            'is_past_due' => $tasking->isPastDue(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function notes(Tasking $tasking): array
    {
        $notes = TaskingNote::query()->where('tasking_id', $tasking->id)->orderBy('id')->get();
        $names = OfficerNames::for($notes->pluck('created_by'));

        return array_values($notes->map(fn (TaskingNote $n) => [
            'ulid' => $n->ulid,
            'body' => RichText::display($n->body),
            'by' => $names->get($n->created_by),
            'at' => $n->created_at->toIso8601String(),
        ])->all());
    }

    /**
     * Every recipient row the viewer can see (directors and national users
     * see them all), with the LGA's current chief.
     *
     * @param  Collection<int, User>  $chiefs  keyed by LGA id
     * @return list<array<string, mixed>>
     */
    public static function recipients(Tasking $tasking, Collection $chiefs): array
    {
        $now = CarbonImmutable::now();
        $rows = TaskingRecipient::query()->with('lga.senatorialDistrict')
            ->where('tasking_id', $tasking->id)
            ->get()
            ->sortBy(fn (TaskingRecipient $r) => $r->lga->name)
            ->values();

        return array_values($rows->map(fn (TaskingRecipient $r) => [
            'ulid' => $r->ulid,
            'lga' => ['ulid' => $r->lga->ulid, 'name' => $r->lga->name],
            'district' => $r->lga->senatorialDistrict->name ?? null,
            'chief' => $chiefs->get($r->lga_id)?->name,
            'status' => self::option($r->status),
            'read_at' => $r->read_at?->toIso8601String(),
            'acknowledged_at' => $r->acknowledged_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'overdue' => $tasking->isSent() && $r->isOverdue($tasking, $now),
        ])->all());
    }

    /**
     * One LGA's row in full: the report back, further responses and their
     * attachments.
     *
     * @return array<string, mixed>
     */
    public static function recipient(Tasking $tasking, TaskingRecipient $recipient, User $viewer): array
    {
        $recipient->loadMissing('lga');
        $responses = TaskingResponse::query()->where('tasking_recipient_id', $recipient->id)->orderBy('id')->get();
        $names = OfficerNames::for([$recipient->read_by, $recipient->acknowledged_by, $recipient->completed_by, ...$responses->pluck('created_by')->all()]);

        return [
            'ulid' => $recipient->ulid,
            'lga' => ['ulid' => $recipient->lga->ulid, 'name' => $recipient->lga->name],
            'status' => self::option($recipient->status),
            'read' => ['at' => $recipient->read_at?->toIso8601String(), 'by' => $names->get($recipient->read_by)],
            'acknowledged' => ['at' => $recipient->acknowledged_at?->toIso8601String(), 'by' => $names->get($recipient->acknowledged_by)],
            'completed' => ['at' => $recipient->completed_at?->toIso8601String(), 'by' => $names->get($recipient->completed_by)],
            'response' => RichText::display($recipient->response),
            'overdue' => $tasking->isSent() && $recipient->isOverdue($tasking),
            'responses' => array_values($responses->map(fn (TaskingResponse $r) => [
                'ulid' => $r->ulid,
                'body' => RichText::display($r->body),
                'by' => $names->get($r->created_by),
                'at' => $r->created_at->toIso8601String(),
            ])->all()),
            'attachments' => self::attachments(
                TaskingAttachment::query()->withCoordinates()->where('tasking_attachments.tasking_recipient_id', $recipient->id)->orderBy('tasking_attachments.id')->get(),
                $viewer,
            ),
        ];
    }

    /**
     * The tasking's own attachments the viewer may see.
     *
     * @return list<array<string, mixed>>
     */
    public static function taskingAttachments(Tasking $tasking, User $viewer): array
    {
        return self::attachments(
            TaskingAttachment::query()->withCoordinates()
                ->where('tasking_attachments.tasking_id', $tasking->id)
                ->whereNull('tasking_attachments.tasking_recipient_id')
                ->orderBy('tasking_attachments.id')
                ->get(),
            $viewer,
        );
    }

    /**
     * Shaped like security report attachments (the same component shows them).
     *
     * @param  Collection<int, TaskingAttachment>  $attachments
     * @return list<array<string, mixed>>
     */
    public static function attachments(Collection $attachments, User $viewer): array
    {
        $names = OfficerNames::for($attachments->pluck('uploaded_by'));

        return array_values($attachments->map(fn (TaskingAttachment $a) => self::attachment($a, $viewer, $names->get($a->uploaded_by)))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function attachment(TaskingAttachment $attachment, User $viewer, ?string $uploader): array
    {
        $media = $attachment->original();
        $clean = $attachment->isScannedClean();
        $image = $attachment->media_type->value === 'image';

        return [
            'ulid' => $attachment->ulid,
            'caption' => $attachment->caption,
            'type' => ['value' => $attachment->media_type->value, 'label' => $attachment->media_type->label()],
            'mime_type' => $attachment->mime_type,
            'original_filename' => $attachment->original_filename,
            'size_bytes' => $attachment->size_bytes,
            'sha256' => $attachment->sha256,
            'captured_at' => $attachment->captured_at?->toIso8601String(),
            'captured_at_local' => $attachment->captured_at?->setTimezone((string) config('app.timezone'))->format('Y-m-d\TH:i'),
            'latitude' => $attachment->latitude === null ? null : (float) $attachment->latitude,
            'longitude' => $attachment->longitude === null ? null : (float) $attachment->longitude,
            'classification' => self::option($attachment->classificationLevel()),
            'processing' => ! $attachment->isProcessed(),
            'scan' => ['value' => $attachment->scan_status->value, 'label' => $attachment->scan_status->label()],
            'has_thumb' => $clean && $image && $media?->hasGeneratedConversion('thumb') === true,
            'has_preview' => $clean && $image && $media?->hasGeneratedConversion('preview') === true,
            'playable' => $clean && $attachment->media_type->value === 'video',
            'uploaded_by' => $uploader,
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'can' => [
                'update' => $viewer->can('update', $attachment),
                'download' => $clean && $viewer->can('download', $attachment),
                'delete' => $viewer->can('delete', $attachment),
            ],
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        return [
            'priorities' => array_map(fn (TaskingPriority $p) => self::option($p), TaskingPriority::cases()),
            'statuses' => array_map(fn (TaskingStatus $s) => self::option($s), TaskingStatus::cases()),
            'targetModes' => array_map(fn (TaskingTargetMode $m) => self::option($m), TaskingTargetMode::cases()),
            'recipientStatuses' => array_map(fn (TaskingRecipientStatus $s) => self::option($s), TaskingRecipientStatus::cases()),
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    public static function option(TaskingPriority|TaskingStatus|TaskingTargetMode|TaskingRecipientStatus|Classification $value): array
    {
        return ['value' => $value->value, 'label' => $value->label()];
    }
}
