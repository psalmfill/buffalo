<?php

namespace App\Support\Onboarding;

use App\Enums\JurisdictionLevel;
use App\Models\State;
use App\Models\User;

/**
 * Onboarding progress per state (Phase 9B.2/9B.3), for the super admin
 * dashboard and the Rollout page. Counts active accounts by stage:
 *
 * - no_link:     setup pending, no usable setup link
 * - link_issued: setup pending, a setup link is waiting to be used
 * - no_2fa:      password chosen, two-factor not enrolled yet
 * - ready:       two-factor enrolled
 *
 * State- and LGA-level users are grouped by their state; national users
 * (and super admins) form one "National" row. Counts only.
 */
final class OnboardingStats
{
    public const STAGES = ['no_link', 'link_issued', 'no_2fa', 'ready'];

    /**
     * @return list<array{state: array{ulid: string, code: string, name: string, is_live: bool, went_live_at: string|null}|null, total: int, no_link: int, link_issued: int, no_2fa: int, ready: int}>
     */
    public function perState(): array
    {
        $now = now();

        $rows = User::query()
            ->where('is_active', true)
            ->selectRaw('CASE WHEN jurisdiction_level IN (?, ?) THEN state_id END AS group_state_id', [JurisdictionLevel::State->value, JurisdictionLevel::Lga->value])
            ->selectRaw('count(*) AS total')
            // A usable setup link: not used and not expired.
            ->selectRaw('count(*) FILTER (WHERE password_changed_at IS NULL AND id NOT IN (SELECT user_id FROM account_setup_tokens WHERE used_at IS NULL AND expires_at > ?)) AS no_link', [$now])
            ->selectRaw('count(*) FILTER (WHERE password_changed_at IS NULL AND id IN (SELECT user_id FROM account_setup_tokens WHERE used_at IS NULL AND expires_at > ?)) AS link_issued', [$now])
            ->selectRaw('count(*) FILTER (WHERE password_changed_at IS NOT NULL AND two_factor_confirmed_at IS NULL) AS no_2fa')
            ->selectRaw('count(*) FILTER (WHERE two_factor_confirmed_at IS NOT NULL) AS ready')
            ->groupByRaw('1')
            ->toBase()
            ->get()
            ->keyBy(fn (\stdClass $row): string => $row->group_state_id === null ? 'national' : (string) $row->group_state_id);

        $counts = fn (?\stdClass $row): array => [
            'total' => (int) ($row->total ?? 0),
            'no_link' => (int) ($row->no_link ?? 0),
            'link_issued' => (int) ($row->link_issued ?? 0),
            'no_2fa' => (int) ($row->no_2fa ?? 0),
            'ready' => (int) ($row->ready ?? 0),
        ];

        $result = [['state' => null, ...$counts($rows->get('national'))]];

        foreach (State::query()->orderBy('name')->get() as $state) {
            $result[] = [
                'state' => [
                    'ulid' => $state->ulid,
                    'code' => $state->code,
                    'name' => $state->name,
                    'is_live' => (bool) $state->is_live,
                    'went_live_at' => $state->went_live_at?->toIso8601String(),
                ],
                ...$counts($rows->get((string) $state->id)),
            ];
        }

        return $result;
    }
}
