<?php

use App\Actions\Auth\IssueAccountSetupLink;
use App\Actions\Auth\ResetTwoFactor;
use App\Actions\Auth\SendAccountSetupLink;
use App\Actions\Auth\SetUserPassword;
use App\Actions\Users\CreateUser;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Actions\Users\UnlockUser;
use App\Actions\Users\UpdateUserJurisdiction;
use App\Actions\Users\UserJurisdiction;
use App\Enums\Role;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\GeopoliticalZone;
use App\Models\Lga;
use App\Models\OfficerPosting;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\User;
use App\Models\Ward;
use App\Support\Audit\AuditExclusions;
use App\Support\Audit\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

function allActivityJson(): string
{
    return DB::table('activity_log')->get()->toJson();
}

test('model changes are logged with only the changed attributes, causer, IP and user agent', function () {
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin);
    $lga = Lga::factory()->create(['name' => 'Before']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7', 'HTTP_USER_AGENT' => 'AuditTest/1.0']);
    request()->server->set('REMOTE_ADDR', '10.9.8.7');
    request()->headers->set('User-Agent', 'AuditTest/1.0');

    $lga->update(['name' => 'After']);

    $entry = Activity::query()->where('subject_type', $lga->getMorphClass())->where('subject_id', $lga->id)->where('event', 'updated')->sole();

    expect($entry->attribute_changes['attributes'])->toBe(['name' => 'After'])
        ->and($entry->attribute_changes['old'])->toBe(['name' => 'Before'])
        ->and($entry->causer?->is($admin))->toBeTrue()
        ->and($entry->properties['ip_address'])->toBe('10.9.8.7')
        ->and($entry->properties['user_agent'])->toBe('AuditTest/1.0');
});

test('creates, deletes and restores are logged', function () {
    $user = User::factory()->directorGeneral()->create();
    $user->delete();
    $user->restore();

    expect(Activity::query()->where('subject_id', $user->id)->where('subject_type', $user->getMorphClass())->pluck('event')->all())
        ->toContain('created', 'deleted', 'restored');
});

test('the audit trait is applied to users and every hierarchy model', function (string $model) {
    expect(in_array(LogsBuffaloActivity::class, class_uses_recursive($model), true))->toBeTrue();
})->with([User::class, GeopoliticalZone::class, State::class, SenatorialDistrict::class, Lga::class, Ward::class, OfficerPosting::class]);

test('excluded attributes never appear in activity entries', function () {
    $user = User::factory()->directorGeneral()->create();
    app(SetUserPassword::class)($user, 'Audit-Password-2026!');
    $user->forceFill(['remember_token' => 'remember-me-token', 'two_factor_secret' => encrypt('SECRETSECRET')])->save();

    // Named events and ad-hoc properties are scrubbed as well.
    AuditLog::event('custom', 'Custom', $user, [
        'password' => 'leak',
        'token' => 'leak',
        'nin_encrypted' => 'leak',
        'phone_bidx' => 'leak',
        'safe' => 'kept',
    ]);

    $json = allActivityJson();

    foreach (['"password"', '"remember_token"', '"two_factor_secret"', '"two_factor_recovery_codes"', '"session_token"', '"token"', 'nin_encrypted', 'phone_bidx', 'remember-me-token', 'leak'] as $needle) {
        expect($json)->not->toContain($needle);
    }

    expect(Activity::query()->where('event', 'custom')->sole()->properties['safe'])->toBe('kept')
        ->and(AuditExclusions::isExcluded('address_bidx'))->toBeTrue()
        ->and(AuditExclusions::isExcluded('name'))->toBeFalse();
});

test('issuing a setup link logs the delivery method but never the link or token', function () {
    Notification::fake();
    $user = User::factory()->directorGeneral()->withoutTwoFactor()->create();

    $url = app(IssueAccountSetupLink::class)($user);
    app(SendAccountSetupLink::class)($user);
    $token = basename(parse_url($url, PHP_URL_PATH));

    $events = Activity::query()->where('event', 'setup_link_issued')->get();

    expect($events->pluck('properties')->map(fn ($p) => $p['delivery'])->all())->toBe(['manual', 'email'])
        ->and(allActivityJson())->not->toContain($token)->not->toContain('/account/setup/');
});

test('named events are logged for user administration', function () {
    $admin = User::factory()->superAdmin()->create();
    User::factory()->superAdmin()->create();
    $lga = Lga::factory()->create();
    $user = app(CreateUser::class)(['name' => 'Officer', 'email' => 'officer@buffalo.test'], new UserJurisdiction(Role::DirectorGeneral), $admin);

    app(UpdateUserJurisdiction::class)($user, new UserJurisdiction(Role::LgaSecurityChief, lga: $lga), $admin);
    app(DeactivateUser::class)($user->fresh(), 'Retired', $admin);
    app(ReactivateUser::class)($user->fresh(), 'Rejoined', $admin);
    app(UnlockUser::class)($user->fresh(), $admin);
    app(ResetTwoFactor::class)($user->fresh(), $admin);

    $events = Activity::query()->where('log_name', 'audit')->where('subject_id', $user->id)->orderBy('id')->get();

    expect($events->pluck('event')->all())->toBe(['role_jurisdiction_changed', 'deactivated', 'reactivated', 'unlocked', 'two_factor_reset'])
        ->and($events->every(fn (Activity $a) => $a->causer?->is($admin)))->toBeTrue()
        ->and($events[0]->properties['old'])->toBe(['role' => 'director_general', 'jurisdiction' => 'National'])
        ->and($events[0]->properties['new']['role'])->toBe('lga_security_chief')
        ->and($events[1]->properties['reason'])->toBe('Retired')
        ->and($events[2]->properties['reason'])->toBe('Rejoined');
});

test('the database rejects updates, deletes and truncation of the activity log', function () {
    AuditLog::event('custom', 'Custom');

    expect(fn () => DB::transaction(fn () => DB::table('activity_log')->update(['description' => 'tampered'])))
        ->toThrow(QueryException::class, 'activity_log is append-only: UPDATE')
        ->and(fn () => DB::transaction(fn () => DB::table('activity_log')->delete()))
        ->toThrow(QueryException::class, 'activity_log is append-only: DELETE')
        ->and(fn () => DB::transaction(fn () => DB::statement('TRUNCATE activity_log')))
        ->toThrow(QueryException::class, 'activity_log is append-only: TRUNCATE');

    expect(Activity::query()->where('event', 'custom')->count())->toBe(1);
});

test('activitylog:clean is disabled', function () {
    AuditLog::event('custom', 'Custom');

    $this->artisan('activitylog:clean', ['--days' => 0, '--force' => true])
        ->expectsOutputToContain('activitylog:clean is disabled')
        ->assertFailed();

    expect(Activity::query()->count())->toBeGreaterThan(0);
});
