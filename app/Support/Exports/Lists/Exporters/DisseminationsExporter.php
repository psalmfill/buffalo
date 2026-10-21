<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\Role;
use App\Models\Flashpoint;
use App\Models\RecordDissemination;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Disseminations (Phase 7A list, 11E export): the received or sent tab, as
 * on the page; only those whose threat report or flashpoint the requester
 * can still see.
 */
final class DisseminationsExporter extends ListExporter
{
    /** @var array<string, array<int, Model>> */
    private array $subjects = [];

    public function key(): string
    {
        return 'disseminations';
    }

    public function label(): string
    {
        return 'Disseminations';
    }

    public function page(): string
    {
        return 'disseminations.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', RecordDissemination::class);
    }

    public function rules(User $user): array
    {
        return ['tab' => ['nullable', Rule::in(['received', 'sent'])]];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<RecordDissemination>
     */
    public function query(User $user, array $filters): Builder
    {
        $query = ($filters['tab'] ?? 'received') === 'sent'
            ? self::sent($user)->orderByDesc('disseminated_at')
            : RecordDissemination::query()->receivedBy($user)
                ->orderByRaw('CASE WHEN revoked_at IS NULL AND expires_at > now() THEN 0 ELSE 1 END')
                ->orderByRaw('acknowledged_at IS NOT NULL')
                ->orderByDesc('disseminated_at');

        // Only records the requester can still see (through their scopes).
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $t) => $t->where('subject_type', 'threat_report')->whereIn('subject_id', ThreatReport::query()->select('threat_reports.id')))
            ->orWhere(fn (Builder $f) => $f->where('subject_type', 'flashpoint')->whereIn('subject_id', Flashpoint::query()->select('flashpoints.id'))))
            ->orderBy('record_disseminations.id');
    }

    /**
     * Disseminations made by the user's jurisdiction (Phase 7A "sent" tab):
     * of records they own, or forwarded by their state.
     *
     * @return Builder<RecordDissemination>
     */
    public static function sent(User $user): Builder
    {
        $owned = fn (string $class) => $class::query()->ownJurisdiction($user)->select($class === ThreatReport::class ? 'threat_reports.id' : 'flashpoints.id');

        return RecordDissemination::query()->where(function (Builder $q) use ($owned, $user): void {
            $q->where(fn (Builder $t) => $t->where('subject_type', 'threat_report')->whereIn('subject_id', $owned(ThreatReport::class)))
                ->orWhere(fn (Builder $f) => $f->where('subject_type', 'flashpoint')->whereIn('subject_id', $owned(Flashpoint::class)))
                ->orWhere('disseminated_by', $user->id);

            if (in_array($user->getRole(), [Role::StateDirector, Role::DeputyStateDirector], true)) {
                $q->orWhereIn('parent_dissemination_id', DB::table('record_disseminations')->where('target_state_id', $user->state_id)->select('id'));
            }
        });
    }

    public function with(): array
    {
        return ['targetState', 'targetLga', 'sourceState', 'sourceLga'];
    }

    public function prepare(Collection $rows, User $user): void
    {
        $this->subjects = [
            'threat_report' => ThreatReport::query()->whereKey($rows->where('subject_type', 'threat_report')->pluck('subject_id')->all())->with('threatType')->get()->keyBy('id')->all(),
            'flashpoint' => Flashpoint::query()->whereKey($rows->where('subject_type', 'flashpoint')->pluck('subject_id')->all())->with('threatType')->get()->keyBy('id')->all(),
        ];
    }

    private function subject(RecordDissemination $d): ThreatReport|Flashpoint|null
    {
        $subject = $this->subjects[$d->subject_type][$d->subject_id] ?? null;

        return $subject instanceof ThreatReport || $subject instanceof Flashpoint ? $subject : null;
    }

    public function columns(): array
    {
        return [
            ListColumn::make('kind', 'Record', fn (RecordDissemination $d) => $this->subject($d)?->disseminationKind()),
            ListColumn::make('label', 'Reference or name', fn (RecordDissemination $d) => $this->subject($d)?->disseminationLabel()),
            ListColumn::make('threat_type', 'Threat type', fn (RecordDissemination $d) => $this->subject($d)?->threatType->name),
            ListColumn::make('source', 'From', fn (RecordDissemination $d) => $d->sourceLga->name.' LGA, '.$d->sourceState->name),
            ListColumn::make('target', 'To', fn (RecordDissemination $d) => $d->targetLga !== null ? $d->targetLga->name.' LGA, '.$d->targetState->name : $d->targetState->name.' State'),
            ListColumn::make('disseminated_at', 'Shared', fn (RecordDissemination $d) => self::at($d->disseminated_at)),
            ListColumn::make('expires_at', 'Expires', fn (RecordDissemination $d) => self::at($d->expires_at)),
            ListColumn::make('acknowledged_at', 'Acknowledged', fn (RecordDissemination $d) => self::at($d->acknowledged_at)),
            ListColumn::make('revoked_at', 'Revoked', fn (RecordDissemination $d) => self::at($d->revoked_at)),
            ListColumn::make('reason', 'Reason for sharing', fn (RecordDissemination $d) => $d->reason),
        ];
    }

    public function defaultColumns(): array
    {
        return ['kind', 'label', 'threat_type', 'source', 'target', 'disseminated_at', 'expires_at', 'acknowledged_at', 'revoked_at'];
    }
}
