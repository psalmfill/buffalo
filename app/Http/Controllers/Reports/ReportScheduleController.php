<?php

namespace App\Http\Controllers\Reports;

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReportRunPresenter;
use App\Http\Requests\Reports\ReportScheduleRequest;
use App\Models\Lga;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\State;
use App\Models\User;
use App\Services\Reports\ReportParameters;
use App\Support\Jurisdiction\JurisdictionOptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's own report schedules (Phase 7D), with one-click defaults:
 * daily SITREP at 07:00, weekly summary on Monday at 08:00 and monthly
 * summary on the 1st at 08:00 (monthly for DG, DDG, SD and DSD).
 */
class ReportScheduleController extends Controller
{
    /** @var array<string, array{type: ReportType, cadence: string, time_of_day: string, weekday: int|null, day_of_month: int|null, label: string}> */
    private const DEFAULTS = [
        'daily_sitrep' => ['type' => ReportType::Sitrep, 'cadence' => 'daily', 'time_of_day' => '07:00', 'weekday' => null, 'day_of_month' => null, 'label' => 'Daily SITREP at 07:00'],
        'weekly_summary' => ['type' => ReportType::Weekly, 'cadence' => 'weekly', 'time_of_day' => '08:00', 'weekday' => 1, 'day_of_month' => null, 'label' => 'Weekly summary on Monday at 08:00'],
        'monthly_summary' => ['type' => ReportType::Monthly, 'cadence' => 'monthly', 'time_of_day' => '08:00', 'weekday' => null, 'day_of_month' => 1, 'label' => 'Monthly summary on the 1st at 08:00'],
    ];

    public function index(Request $request, JurisdictionOptions $options): Response
    {
        $user = $this->actor($request);
        $types = array_values(array_filter([ReportType::Sitrep, ReportType::Weekly, ReportType::Monthly], fn (ReportType $t) => $user->can('schedule', [ReportRun::class, $t])));
        abort_if($types === [], 403);
        $schedules = ReportSchedule::query()->ownedBy($user)->orderBy('id')->get();
        $states = $options->states($user);

        return Inertia::render('reports/schedules', [
            'schedules' => $schedules->map(fn (ReportSchedule $s) => ReportRunPresenter::schedule($s, $user))->values(),
            'types' => array_map(fn (ReportType $t) => ['value' => $t->value, 'label' => $t->label()], $types),
            'defaults' => collect(self::DEFAULTS)->filter(fn (array $d) => in_array($d['type'], $types, true))
                ->map(fn (array $d, string $key) => [
                    'key' => $key,
                    'label' => $d['label'],
                    'exists' => $schedules->contains(fn (ReportSchedule $s) => $s->report_type === $d['type'] && $s->cadence === $d['cadence'] && $s->parameters === []),
                ])->values(),
            'options' => [
                'states' => $states->count() > 1 ? $states->sortBy('name')->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values() : [],
                'lgas' => $options->lgaQuery($user)->active()->with('state:id,ulid')->orderBy('name')->get()
                    ->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name, 'state' => $l->state->ulid])->values(),
            ],
        ]);
    }

    public function store(ReportScheduleRequest $request, ReportParameters $parameters): RedirectResponse
    {
        $type = $request->reportType();
        $this->authorize('schedule', [ReportRun::class, $type]);
        $user = $this->actor($request);
        // Only the place is saved; periods follow the schedule.
        $place = $parameters->validate(ReportType::Sitrep, (array) $request->validated('parameters', []), $user);

        $this->create($user, $type, $request->string('cadence')->toString(), $request->string('time_of_day')->toString(),
            $request->validated('cadence') === 'weekly' ? (int) $request->validated('weekday') : null,
            $request->validated('cadence') === 'monthly' ? (int) $request->validated('day_of_month') : null, $place);

        return back()->with('toast', ['type' => 'success', 'message' => 'Schedule added.']);
    }

    public function storeDefault(Request $request, string $key): RedirectResponse
    {
        abort_unless(isset(self::DEFAULTS[$key]), 404);
        $default = self::DEFAULTS[$key];
        $this->authorize('schedule', [ReportRun::class, $default['type']]);
        $this->create($this->actor($request), $default['type'], $default['cadence'], $default['time_of_day'], $default['weekday'], $default['day_of_month'], []);

        return back()->with('toast', ['type' => 'success', 'message' => $default['label'].' added.']);
    }

    public function update(Request $request, ReportSchedule $schedule): RedirectResponse
    {
        $this->authorize('manageSchedule', $schedule);
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        if ($active) {
            // Resuming must still be allowed (e.g. after a role change).
            $this->authorize('schedule', [ReportRun::class, $schedule->report_type]);
        }

        $schedule->forceFill([
            'is_active' => (bool) $active,
            'next_run_at' => $active ? $schedule->nextRunAfter(CarbonImmutable::now()) : $schedule->next_run_at,
        ])->save();

        return back()->with('toast', ['type' => 'success', 'message' => $active ? 'Schedule resumed.' : 'Schedule paused.']);
    }

    public function destroy(Request $request, ReportSchedule $schedule): RedirectResponse
    {
        $this->authorize('manageSchedule', $schedule);
        $schedule->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Schedule removed.']);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function create(User $user, ReportType $type, string $cadence, string $time, ?int $weekday, ?int $day, array $parameters): void
    {
        $schedule = new ReportSchedule;
        $schedule->forceFill([
            'user_id' => $user->id,
            'report_type' => $type,
            'parameters' => $parameters,
            'format' => ReportFormat::Pdf,
            'cadence' => $cadence,
            'time_of_day' => $time,
            'weekday' => $weekday,
            'day_of_month' => $day,
            'is_active' => true,
            'next_run_at' => ReportSchedule::next($cadence, $time, $weekday, $day, CarbonImmutable::now()),
        ])->save();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
