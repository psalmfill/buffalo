<?php

namespace App\Support\Dashboard;

use App\Enums\JurisdictionLevel;
use App\Enums\Permission;
use App\Enums\TaskingRecipientStatus;
use App\Enums\TaskingStatus;
use App\Http\Presenters\TaskingPresenter;
use App\Models\Tasking;
use App\Models\TaskingRecipient;
use App\Models\User;
use App\Support\Taskings\TaskingProgress;
use Carbon\CarbonImmutable;

/**
 * Tasking cards and badges (Phase 11H), always through the scopes:
 *
 * - directors (State Director and Deputy): the Tasking card at the top of
 *   the dashboard, with the latest sent taskings and their progress line;
 *   the sidebar badge counts taskings with an LGA overdue;
 * - LGA chiefs: "Messages from the Director", their LGA's latest taskings
 *   with unread ones first in weight; the badge counts unread taskings.
 */
final class TaskingFigures
{
    public const LATEST = 5;

    public function badge(User $user): int
    {
        if (! $user->checkPermissionTo(Permission::TaskingsView->value)) {
            return 0;
        }

        if ($user->jurisdiction_level === JurisdictionLevel::Lga) {
            return $this->unread($user);
        }

        if (! $user->checkPermissionTo(Permission::TaskingsSend->value)) {
            return 0;
        }

        return Tasking::query()
            ->where('taskings.state_id', $user->state_id)
            ->where('taskings.status', TaskingStatus::Sent->value)
            ->whereNotNull('taskings.response_due_at')
            ->where('taskings.response_due_at', '<=', CarbonImmutable::now())
            ->whereHas('recipients', fn ($q) => $q->where('tasking_recipients.status', '<>', TaskingRecipientStatus::Completed->value))
            ->count();
    }

    /**
     * The directors' card.
     *
     * @return array{canCreate: bool, latest: list<array<string, mixed>>, drafts: int}|null
     */
    public function forDirector(User $user): ?array
    {
        if (! $user->can('viewAny', Tasking::class) || $user->jurisdiction_level !== JurisdictionLevel::State) {
            return null;
        }

        $latest = Tasking::query()
            ->where('taskings.state_id', $user->state_id)
            ->delivered()
            ->orderByDesc('taskings.sent_at')
            ->limit(self::LATEST)
            ->get();
        $progress = TaskingProgress::for(array_values(array_map('intval', $latest->modelKeys())));

        return [
            'canCreate' => $user->can('create', Tasking::class),
            'latest' => array_values($latest->map(fn (Tasking $t) => TaskingPresenter::row($t, $progress[$t->id] ?? null))->all()),
            'drafts' => $user->can('create', Tasking::class)
                ? Tasking::query()->where('taskings.state_id', $user->state_id)->where('taskings.status', TaskingStatus::Draft->value)->count()
                : 0,
        ];
    }

    /**
     * "Messages from the Director" for an LGA chief.
     *
     * @return array{unread: int, latest: list<array<string, mixed>>}|null
     */
    public function forLgaChief(User $user): ?array
    {
        if (! $user->can('viewAny', Tasking::class) || $user->jurisdiction_level !== JurisdictionLevel::Lga) {
            return null;
        }

        $latest = Tasking::query()->delivered()->orderByDesc('taskings.sent_at')->limit(self::LATEST)->get();
        $mine = TaskingRecipient::query()->whereIn('tasking_id', $latest->modelKeys())->where('lga_id', $user->lga_id)->get()->keyBy('tasking_id');

        return [
            'unread' => $this->unread($user),
            'latest' => array_values($latest->map(fn (Tasking $t) => TaskingPresenter::row($t, null, $mine->get($t->id), forLga: true))->all()),
        ];
    }

    private function unread(User $user): int
    {
        return TaskingRecipient::query()
            ->where('tasking_recipients.lga_id', $user->lga_id)
            ->where('tasking_recipients.status', TaskingRecipientStatus::Delivered->value)
            ->whereHas('tasking', fn ($q) => $q->where('taskings.status', TaskingStatus::Sent->value))
            ->count();
    }
}
