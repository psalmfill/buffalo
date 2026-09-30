<?php

use App\Models\LgaProfile;
use App\Models\Person;
use App\Models\PersonOfInterest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(fn () => CrossFixtures::system());

test('the database session runs in the app time zone', function () {
    expect(DB::selectOne('SHOW timezone')->TimeZone)->toBe('Africa/Lagos')
        ->and(config('app.timezone'))->toBe('Africa/Lagos');
});

test('a Lagos time round-trips through timestamptz and timestamp columns, and is stored as the right instant', function () {
    $lagos = CarbonImmutable::parse('2026-09-30 14:22:00', 'Africa/Lagos');

    $tz = DB::selectOne("SELECT ?::timestamptz AS v, (?::timestamptz AT TIME ZONE 'UTC') AS utc", [$lagos->format('Y-m-d H:i:s'), $lagos->format('Y-m-d H:i:s')]);
    $plain = DB::selectOne('SELECT ?::timestamp AS v', [$lagos->format('Y-m-d H:i:s')]);

    expect(CarbonImmutable::parse($tz->v)->setTimezone('Africa/Lagos')->format('Y-m-d H:i'))->toBe('2026-09-30 14:22')
        ->and(substr((string) $tz->utc, 0, 16))->toBe('2026-09-30 13:22')
        ->and(substr((string) $plain->v, 0, 16))->toBe('2026-09-30 14:22');
});

test('the three Phase 4 columns display the time that was written', function () {
    $world = JurisdictionWorld::build();
    $written = CarbonImmutable::parse('2026-09-30 09:15:00', 'Africa/Lagos');
    $poi = PersonOfInterest::factory()->forPerson(Person::factory()->in($world->lgas['A1a'])->create())->create();
    $poi->forceFill(['last_sighted_at' => $written, 'last_reviewed_at' => $written])->save();
    $profile = new LgaProfile;
    $profile->forceFill(['lga_id' => $world->lgas['A1a']->id, 'state_id' => $world->lgas['A1a']->state_id, 'last_reviewed_at' => $written])->save();

    $poi = PersonOfInterest::query()->findOrFail($poi->id);
    expect($poi->last_sighted_at?->setTimezone('Africa/Lagos')->format('H:i'))->toBe('09:15')
        ->and($poi->last_reviewed_at?->setTimezone('Africa/Lagos')->format('H:i'))->toBe('09:15')
        ->and(LgaProfile::query()->findOrFail($profile->id)->last_reviewed_at?->setTimezone('Africa/Lagos')->format('H:i'))->toBe('09:15')
        // The stored instant is 08:15 UTC.
        ->and(substr((string) DB::table('persons_of_interest')->where('id', $poi->id)->selectRaw("last_sighted_at AT TIME ZONE 'UTC' AS utc")->value('utc'), 11, 5))->toBe('08:15');
});
