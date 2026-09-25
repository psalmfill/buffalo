<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only audit log viewer (audit.view with system or national reach).
 * There are no edit or delete routes; the table is append-only.
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->authorize('viewAudit');

        $filters = $request->validate([
            'causer' => ['nullable', 'string', 'max:26'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $subjectTypes = Activity::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type');
        $events = Activity::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event');

        $causer = isset($filters['causer']) ? User::query()->withTrashed()->where('ulid', $filters['causer'])->first() : null;

        $entries = Activity::query()
            ->with(['causer', 'subject'])
            ->when($causer, fn (Builder $q) => $q->where('causer_type', $causer?->getMorphClass())->where('causer_id', $causer?->id))
            ->when(isset($filters['causer']) && $causer === null, fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->when($filters['subject_type'] ?? null, fn (Builder $q, string $type) => $q->where('subject_type', $type))
            ->when($filters['event'] ?? null, fn (Builder $q, string $event) => $q->where('event', $event))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Activity $activity) => ActivityPresenter::present($activity));

        return Inertia::render('audit/index', [
            'entries' => $entries,
            'filters' => [
                'causer' => $filters['causer'] ?? '',
                'subject_type' => $filters['subject_type'] ?? '',
                'event' => $filters['event'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'subjectTypes' => $subjectTypes->map(fn (string $type) => ['value' => $type, 'label' => class_basename($type)])->values(),
            'events' => $events->values(),
            'causers' => User::query()->orderBy('name')->get(['ulid', 'name'])->map(fn (User $u) => ['ulid' => $u->ulid, 'name' => $u->name])->values(),
        ]);
    }
}
