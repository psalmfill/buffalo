<?php

use App\Enums\Classification;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Jurisdiction\JurisdictionWorld;

const MAX_LEVELS = [
    'super_admin' => null,
    'director_general' => 'secret',
    'ddg_national' => 'secret',
    'ddg_zone_a' => 'secret',
    'state_director' => 'secret',
    'deputy_state_director' => 'confidential',
    'lga_chief' => 'restricted',
    'inactive_dg' => null,
    'locked_dg' => null,
    'no_role' => null,
];

test('each role has its maximum classification', function (string $who, ?string $max) {
    $world = JurisdictionWorld::build();

    expect(app(ClassificationGate::class)->maxLevelFor($world->users[$who])?->value)->toBe($max);
})->with(fn () => collect(MAX_LEVELS)->map(fn ($max, $who) => [$who, $max])->values()->all());

test('canSee and canAssign for every role and level, with the creator exception', function (string $who, ?string $max) {
    $world = JurisdictionWorld::build();
    $user = $world->users[$who];
    $gate = app(ClassificationGate::class);
    $someoneElse = User::factory()->directorGeneral()->create();

    foreach (Classification::cases() as $level) {
        $allowed = $max !== null && $level->isAtMost(Classification::from($max));

        $byOther = new CommunitySecurityNote(['classification' => $level]);
        $byOther->forceFill(['classification' => $level, 'created_by' => $someoneElse->id]);
        $own = (new CommunitySecurityNote)->forceFill(['classification' => $level, 'created_by' => $user->id]);

        expect($gate->canSee($user, $byOther))->toBe($allowed, "{$who} sees {$level->value}")
            ->and($gate->canAssign($user, $level))->toBe($allowed, "{$who} assigns {$level->value}")
            // The creator exception, but never for users without intelligence access.
            ->and($gate->canSee($user, $own))->toBe($max !== null, "{$who} sees own {$level->value}");
    }
})->with(fn () => collect(MAX_LEVELS)->map(fn ($max, $who) => [$who, $max])->values()->all());

test('the classification scope composes with jurisdiction', function () {
    $world = JurisdictionWorld::build();
    $deputy = $world->users['deputy_state_director'];
    $notes = [];

    foreach (['A1a', 'A1b', 'B1'] as $place) {
        $community = Community::factory()->in($world->lgas[$place])->create();

        foreach (Classification::cases() as $level) {
            $notes["{$place}-{$level->value}"] = CommunitySecurityNote::factory()->in($community)->classified($level)->create(['title' => "{$place}-{$level->value}"]);
        }
    }

    // A secret note in the deputy's state that the deputy wrote.
    $ownSecret = CommunitySecurityNote::factory()->in(Community::factory()->in($world->lgas['A1b'])->create())
        ->classified(Classification::Secret)->create(['title' => 'A1b-own-secret', 'created_by' => $deputy->id]);
    // A secret note in another state the deputy wrote: still out of jurisdiction.
    CommunitySecurityNote::factory()->in(Community::factory()->in($world->lgas['B1'])->create())
        ->classified(Classification::Secret)->create(['title' => 'B1-own-secret', 'created_by' => $deputy->id]);

    $visible = fn (string $who) => app(JurisdictionContext::class)->actingAs(
        $world->users[$who]->fresh() ?? $world->users[$who],
        fn () => CommunitySecurityNote::query()->orderBy('title')->pluck('title')->all(),
    );

    expect($visible('deputy_state_director'))->toBe(['A1a-confidential', 'A1a-restricted', 'A1b-confidential', 'A1b-own-secret', 'A1b-restricted'])
        ->and($visible('state_director'))->toBe(['A1a-confidential', 'A1a-restricted', 'A1a-secret', 'A1b-confidential', 'A1b-own-secret', 'A1b-restricted', 'A1b-secret'])
        ->and($visible('lga_chief'))->toBe(['A1a-restricted'])
        ->and($visible('director_general'))->toHaveCount(11)
        ->and($visible('super_admin'))->toBe([])
        ->and($visible('no_role'))->toBe([])
        ->and($visible('inactive_dg'))->toBe([]);

    // No user context: nothing. System context: everything.
    app(JurisdictionContext::class)->none();
    expect(CommunitySecurityNote::query()->count())->toBe(0);
    app(JurisdictionContext::class)->system();
    expect(CommunitySecurityNote::query()->count())->toBe(11)
        ->and($ownSecret->exists)->toBeTrue();
});

test('changing a classification is logged with the old and new levels', function () {
    $world = JurisdictionWorld::build();
    $note = CommunitySecurityNote::factory()->in(Community::factory()->in($world->lgas['A1a'])->create())->create();

    $note->forceFill(['classification' => Classification::Secret])->save();
    $note->forceFill(['classification' => Classification::Confidential])->save();
    $note->forceFill(['title' => 'Only the title'])->save();

    $events = Activity::query()->where('event', 'classification_changed')->orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->properties->only(['old', 'new'])->all())->toBe(['old' => 'restricted', 'new' => 'secret'])
        ->and($events[0]->description)->toBe('Classification raised from restricted to secret')
        ->and($events[1]->properties->only(['old', 'new'])->all())->toBe(['old' => 'secret', 'new' => 'confidential'])
        ->and($events[1]->description)->toBe('Classification lowered from secret to confidential')
        ->and($events[0]->subject_id)->toBe($note->id);
});

test('no code outside the classification support namespace removes ClassificationScope', function () {
    $allowed = (string) realpath(base_path('app/Support/Classification'));
    $violations = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes'] as $directory) {
        foreach (File::allFiles(base_path($directory)) as $file) {
            $path = (string) $file->getRealPath();

            if ($file->getExtension() === 'php' && ! str_starts_with($path, $allowed)
                && preg_match('/withoutGlobalScope\s*\(\s*[^)]*ClassificationScope/', $file->getContents()) === 1) {
                $violations[] = $path;
            }
        }
    }

    expect($violations)->toBe([]);
});

test('the frontend receives the user\'s maximum classification', function () {
    $world = JurisdictionWorld::build();

    $this->actingAs($world->users['deputy_state_director'])->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.maxClassification', 'confidential'));
});
