<?php

use App\Enums\LoginEvent;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Auth\LoginHistoryRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('the recorder stores the event with request details', function () {
    $user = User::factory()->directorGeneral()->create(['email' => 'officer@buffalo.test']);

    $entry = app(LoginHistoryRecorder::class)->record(LoginEvent::LoginSuccess, $user);

    expect($entry->fresh())
        ->event->toBe(LoginEvent::LoginSuccess)
        ->user_id->toBe($user->id)
        ->email_attempted->toBe('officer@buffalo.test')
        ->created_at->not->toBeNull();
});

test('the database rejects updates, deletes and truncation of login history', function () {
    app(LoginHistoryRecorder::class)->record(LoginEvent::LoginFailed, emailAttempted: 'someone@buffalo.test');

    // Each attempt runs in its own savepoint so the test transaction survives.
    expect(fn () => DB::transaction(fn () => DB::table('login_histories')->update(['event' => 'logout'])))
        ->toThrow(QueryException::class, 'login_histories is append-only: UPDATE')
        ->and(fn () => DB::transaction(fn () => DB::table('login_histories')->delete()))
        ->toThrow(QueryException::class, 'login_histories is append-only: DELETE')
        ->and(fn () => DB::transaction(fn () => DB::statement('TRUNCATE login_histories')))
        ->toThrow(QueryException::class, 'login_histories is append-only: TRUNCATE');

    expect(LoginHistory::query()->count())->toBe(1);
});

test('the model refuses updates and deletes as well', function () {
    $entry = app(LoginHistoryRecorder::class)->record(LoginEvent::Logout);

    expect(fn () => $entry->update(['event' => LoginEvent::LoginSuccess]))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(LoginHistory::query()->count())->toBe(1);
});

test('only known events can be stored', function () {
    expect(fn () => DB::table('login_histories')->insert(['event' => 'made_up']))
        ->toThrow(QueryException::class, 'login_histories_event_check');
});
