<?php

namespace App\Support\CrossJurisdiction;

use App\Enums\MatchType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Hit tokens (Phase 3C): Laravel-encrypted (authenticated, so tampering
 * is detected), bound to one user, valid for a short time. The only way
 * to request access to a hit: the client never sends a record identifier.
 */
final class HitToken
{
    public static function issue(int $personId, User $user, MatchType $type, int $matchEventId): string
    {
        $minutes = (int) config('buffalo.cross_jurisdiction.token_minutes', 120);

        return Crypt::encryptString((string) json_encode([
            'p' => $personId,
            'u' => $user->id,
            't' => $type->value,
            'e' => $matchEventId,
            'x' => CarbonImmutable::now()->addMinutes($minutes)->getTimestamp(),
        ]));
    }

    /**
     * @return array{person_id: int, match_type: MatchType, match_event_id: int}
     *
     * @throws InvalidHitToken
     */
    public static function decode(string $token, User $user): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new InvalidHitToken('The hit is not valid.');
        }

        if (! is_array($data) || ! isset($data['p'], $data['u'], $data['t'], $data['e'], $data['x'])) {
            throw new InvalidHitToken('The hit is not valid.');
        }

        if ((int) $data['u'] !== $user->id) {
            throw new InvalidHitToken('The hit belongs to another user.');
        }

        if ((int) $data['x'] <= CarbonImmutable::now()->getTimestamp()) {
            throw new InvalidHitToken('The hit has expired. Search again.');
        }

        $type = MatchType::tryFrom((string) $data['t']);

        if ($type === null) {
            throw new InvalidHitToken('The hit is not valid.');
        }

        return ['person_id' => (int) $data['p'], 'match_type' => $type, 'match_event_id' => (int) $data['e']];
    }
}
