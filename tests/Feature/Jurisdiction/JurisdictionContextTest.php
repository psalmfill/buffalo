<?php

use App\Models\User;
use App\Support\Jurisdiction\ContextMode;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Jurisdiction\ScopedFixtureRecord;

beforeEach(function () {
    $this->world = JurisdictionWorld::build();
    $this->context = app(JurisdictionContext::class);
});

/**
 * A queued job acting for a user: it must scope itself with actingAs().
 */
class JurisdictionProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** @var array<string, list<string>> */
    public static array $seen = [];

    public function __construct(public User $user) {}

    public function handle(JurisdictionContext $context): void
    {
        self::$seen['before'] = ScopedFixtureRecord::query()->orderBy('label')->pluck('label')->all();
        self::$seen['acting'] = $context->actingAs($this->user, fn () => ScopedFixtureRecord::query()->orderBy('label')->pluck('label')->all());
        self::$seen['after'] = ScopedFixtureRecord::query()->orderBy('label')->pluck('label')->all();
    }
}

test('console and queue code starts in system mode', function () {
    expect(app()->runningInConsole())->toBeTrue();

    app()->forgetScopedInstances();

    expect(app(JurisdictionContext::class)->mode())->toBe(ContextMode::System);
});

test('actingAs scopes a queued job to the user and restores the mode afterwards', function () {
    $this->context->system();
    JurisdictionProbeJob::$seen = [];

    JurisdictionProbeJob::dispatch($this->world->users['lga_chief']);

    expect(JurisdictionProbeJob::$seen['acting'])->toBe(['own_lga'])
        ->and(JurisdictionProbeJob::$seen['before'])->toHaveCount(5)
        ->and(JurisdictionProbeJob::$seen['after'])->toHaveCount(5)
        ->and($this->context->mode())->toBe(ContextMode::System);
});

test('actingAs restores the previous context when the callback throws', function () {
    $this->context->user($this->world->users['state_director']);

    expect(fn () => $this->context->actingAs($this->world->users['lga_chief'], fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect($this->context->currentUser()?->is($this->world->users['state_director']))->toBeTrue();
});

test('runAsSystem lifts the scope, restores the context and records the reason', function () {
    $director = $this->world->users['state_director'];
    $this->context->user($director);

    $count = $this->context->runAsSystem('National match check for test', fn () => ScopedFixtureRecord::query()->count());

    expect($count)->toBe(5)
        ->and($this->context->mode())->toBe(ContextMode::User)
        ->and($this->context->currentUser()?->is($director))->toBeTrue()
        ->and(ScopedFixtureRecord::query()->count())->toBe(3);

    $activity = Activity::query()->where('log_name', 'jurisdiction')->sole();

    expect($activity->event)->toBe('system_bypass')
        ->and($activity->properties['reason'])->toBe('National match check for test')
        ->and($activity->properties['previous_mode'])->toBe('user')
        ->and($activity->causer?->is($director))->toBeTrue();
});

test('runAsSystem restores the context and still logs when the callback throws', function () {
    $this->context->user($this->world->users['lga_chief']);

    expect(fn () => $this->context->runAsSystem('Failing bypass', fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect($this->context->currentUser()?->is($this->world->users['lga_chief']))->toBeTrue()
        ->and(ScopedFixtureRecord::query()->count())->toBe(1)
        ->and(Activity::query()->where('log_name', 'jurisdiction')->where('description', 'like', '%Failing bypass%')->exists())->toBeTrue();
});

test('runAsSystem requires a reason', function () {
    $this->context->runAsSystem('   ', fn () => null);
})->throws(InvalidArgumentException::class);

test('runAsSystem refuses to run when activity logging is disabled', function () {
    config(['activitylog.enabled' => false]);
    $this->context->user($this->world->users['lga_chief']);

    expect(fn () => $this->context->runAsSystem('No audit', fn () => ScopedFixtureRecord::query()->count()))
        ->toThrow(RuntimeException::class, 'Activity logging is disabled');

    expect(ScopedFixtureRecord::query()->count())->toBe(1);
});

test('HTTP requests without a user run in none mode; authenticated ones in the user\'s context', function () {
    Route::middleware('web')->get('/_test/jurisdiction', fn (JurisdictionContext $context) => [
        'mode' => $context->mode()->value,
        'records' => ScopedFixtureRecord::query()->count(),
    ]);

    $this->get('/_test/jurisdiction')->assertExactJson(['mode' => 'none', 'records' => 0]);

    $this->actingAs($this->world->users['state_director'])
        ->get('/_test/jurisdiction')
        ->assertExactJson(['mode' => 'user', 'records' => 3]);
});

test('route model binding is jurisdiction-scoped (context is set before bindings)', function () {
    Route::middleware(['web', 'auth'])->get('/_test/users/{user}', fn (User $user) => ['ulid' => $user->ulid]);

    $director = $this->world->users['state_director'];
    $inState = $this->world->users['lga_chief'];
    $outOfState = User::factory()->stateDirector($this->world->states['B1'])->create();

    $this->actingAs($director)->get("/_test/users/{$inState->ulid}")->assertOk()->assertJson(['ulid' => $inState->ulid]);
    $this->actingAs($director)->get("/_test/users/{$outOfState->ulid}")->assertNotFound();
});

test('users can still log in while unauthenticated requests are in none mode', function () {
    $user = $this->world->users['lga_chief'];
    // Without 2FA the password step logs in directly (enrolment follows).
    $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});
