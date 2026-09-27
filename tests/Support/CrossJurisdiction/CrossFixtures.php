<?php

namespace Tests\Support\CrossJurisdiction;

use App\Actions\AccessRequests\DecideAccessRequest;
use App\Actions\AccessRequests\RequestAccess;
use App\Enums\GrantScope;
use App\Enums\MatchContext;
use App\Models\Lga;
use App\Models\Person;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessRequest;
use App\Models\User;
use App\Support\CrossJurisdiction\MatchCriteria;
use App\Support\CrossJurisdiction\MatchResult;
use App\Support\CrossJurisdiction\PersonMatchService;
use App\Support\Jurisdiction\JurisdictionContext;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\TestCase;

/**
 * Helpers for the cross-jurisdiction tests (Phase 3C). World: states A1
 * (LGAs A1a, A1b) and A2 in zone A, B1 in zone B; see JurisdictionWorld.
 */
final class CrossFixtures
{
    public const NIN = '52345678901';

    public static function system(): void
    {
        app(JurisdictionContext::class)->system();
    }

    public static function person(Lga $lga, array $attributes = []): Person
    {
        self::system();

        return Person::factory()->in($lga)->named('Garba', 'Musa', 'Danjuma')->create(['nin' => self::NIN, ...$attributes]);
    }

    public static function match(User $user, MatchCriteria $criteria, MatchContext $context = MatchContext::PersonSearch): MatchResult
    {
        return app(JurisdictionContext::class)->actingAs($user, fn () => app(PersonMatchService::class)->match($criteria, $user, $context));
    }

    public static function byNin(User $user, string $nin = self::NIN): MatchResult
    {
        return self::match($user, new MatchCriteria(nin: $nin));
    }

    /**
     * A request by $user for the B1 person with the fixture NIN.
     *
     * @param  array<string, mixed>  $data
     */
    public static function request(User $user, array $data = []): RecordAccessRequest
    {
        $token = self::byNin($user)->hits[0]->token;

        return app(RequestAccess::class)($user, $token, [...self::requestData(), ...$data]);
    }

    /** @return array{reason: string, reference: string, scope: string, urgency: string, requested_days: int} */
    public static function requestData(): array
    {
        return [
            'reason' => 'Suspect arrested in our LGA with links to a kidnapping ring.',
            'reference' => 'CASE-2026-114',
            'scope' => 'full',
            'urgency' => 'normal',
            'requested_days' => 14,
        ];
    }

    public static function grant(User $holder, User $decider, GrantScope $scope = GrantScope::Full, int $days = 14): RecordAccessGrant
    {
        return app(DecideAccessRequest::class)->approve(self::request($holder), $decider, $days, $scope, null);
    }

    /** A State Director of state B1 (the "other" state), who owns B1 records. */
    public static function directorOfB1(JurisdictionWorld $world): User
    {
        return User::factory()->stateDirector($world->states['B1'])->create();
    }

    public static function deputyOfB1(JurisdictionWorld $world): User
    {
        return User::factory()->deputyStateDirector($world->states['B1'])->create();
    }

    public static function chiefOf(Lga $lga): User
    {
        return User::factory()->lgaChief($lga)->create();
    }

    /**
     * Signs in as $user with a fresh session. The single-session policy binds
     * the session to users.session_token on first use, so it is reset too.
     */
    public static function signIn(User $user): TestCase
    {
        $user->forceFill(['session_token' => null])->saveQuietly();

        return test()->flushSession()->actingAs($user);
    }

    /**
     * Every string in a nested array (props walking).
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[] = $key;
            }

            array_push($out, ...self::strings($item));
        }

        return $out;
    }
}
