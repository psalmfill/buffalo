<?php

namespace App\Support\Lens;

use App\Enums\JurisdictionLevel;
use App\Models\GeopoliticalZone;
use App\Models\State;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * The state lens (Phase 11A.2): a national user's choice to look at the
 * country one state or one zone at a time, kept in the session.
 *
 * It changes what they look at FIRST, never what they can see: dashboards
 * open on the state or zone, lists, the map and analytics open with their
 * place filter pre-set, and search ranks that state first. Scopes are
 * untouched. Only states and zones within the user's reach can be chosen
 * (a zoned DDG: their zones' states), and a lens that is no longer within
 * reach is ignored. Users with state or LGA jurisdiction have no lens.
 */
final class StateLens
{
    public const SESSION_KEY = 'buffalo.lens';

    public function __construct(private readonly JurisdictionOptions $options) {}

    public function available(?User $user): bool
    {
        return $user instanceof User && $user->jurisdiction_level === JurisdictionLevel::National && ! $user->isAccessBlocked();
    }

    /**
     * @return Collection<int, State>
     */
    public function states(User $user): Collection
    {
        return $this->available($user) ? $this->options->states($user) : new Collection;
    }

    /**
     * Zones with at least one state within reach (a zoned DDG: their zones).
     *
     * @return Collection<int, GeopoliticalZone>
     */
    public function zones(User $user): Collection
    {
        $states = $this->states($user);

        return GeopoliticalZone::query()->whereIn('id', $states->pluck('geopolitical_zone_id')->filter()->unique()->all())->orderBy('name')->get();
    }

    public function canChooseState(User $user, State $state): bool
    {
        return $this->states($user)->contains('id', $state->id);
    }

    public function canChooseZone(User $user, GeopoliticalZone $zone): bool
    {
        return $this->zones($user)->contains('id', $zone->id);
    }

    /**
     * The current lens, if any and still within reach.
     *
     * @return array{type: 'state'|'zone', ulid: string, name: string, model: State|GeopoliticalZone}|null
     */
    public function current(Request $request): ?array
    {
        $user = $request->user();
        $stored = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (! $user instanceof User || ! $this->available($user) || ! is_array($stored) || ! is_string($stored['ulid'] ?? null)) {
            return null;
        }

        if (($stored['type'] ?? null) === 'state') {
            $state = State::query()->where('ulid', $stored['ulid'])->first();

            return $state instanceof State && $this->canChooseState($user, $state)
                ? ['type' => 'state', 'ulid' => $state->ulid, 'name' => $state->name, 'model' => $state]
                : null;
        }

        $zone = GeopoliticalZone::query()->where('ulid', $stored['ulid'])->first();

        return $zone instanceof GeopoliticalZone && $this->canChooseZone($user, $zone)
            ? ['type' => 'zone', 'ulid' => $zone->ulid, 'name' => $zone->name, 'model' => $zone]
            : null;
    }

    public function set(Request $request, State|GeopoliticalZone $place): void
    {
        $request->session()->put(self::SESSION_KEY, ['type' => $place instanceof State ? 'state' : 'zone', 'ulid' => $place->ulid]);
    }

    public function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * The list filter the lens pre-sets: ['state' => ulid] or ['zone' => ulid].
     *
     * @return array<string, string>
     */
    public function filter(Request $request): array
    {
        $lens = $this->current($request);

        return $lens === null ? [] : [$lens['type'] => $lens['ulid']];
    }

    /**
     * The states the lens covers (one, or a zone's within reach).
     *
     * @return list<int>
     */
    public function stateIds(Request $request): array
    {
        $lens = $this->current($request);
        $user = $request->user();

        if ($lens === null || ! $user instanceof User) {
            return [];
        }

        return $lens['model'] instanceof State
            ? [$lens['model']->id]
            : array_values(array_map('intval', $this->states($user)->where('geopolitical_zone_id', $lens['model']->id)->modelKeys()));
    }

    /**
     * Shared Inertia prop: null for users without a lens control.
     *
     * @return array<string, mixed>|null
     */
    public function share(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->available($user)) {
            return null;
        }

        $lens = $this->current($request);

        return [
            'current' => $lens === null ? null : ['type' => $lens['type'], 'ulid' => $lens['ulid'], 'name' => $lens['name']],
            'zones' => $this->zones($user)->map(fn (GeopoliticalZone $z) => ['ulid' => $z->ulid, 'name' => $z->name])->values()->all(),
            'states' => $this->states($user)->map(fn (State $s) => ['ulid' => $s->ulid, 'name' => $s->name, 'zone' => $s->geopoliticalZone?->ulid])->values()->all(),
        ];
    }
}
