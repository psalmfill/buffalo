<?php

use App\Enums\AccessAction;
use App\Models\RecordAccessLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Audit\AccessLoggedFixture;

beforeEach(function () {
    AccessLoggedFixture::createTable();
    $this->record = AccessLoggedFixture::query()->create(['label' => 'Sensitive']);
});

test('a controller showing an access-logged record writes one entry', function () {
    Route::middleware(['web', 'auth'])->get('/_test/fixture/{id}', function (string $id) {
        $record = AccessLoggedFixture::query()->findOrFail($id);
        $record->recordAccess(context: 'search');

        return ['label' => $record->label];
    });

    $user = User::factory()->directorGeneral()->create();

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '10.2.3.4'])
        ->get("/_test/fixture/{$this->record->id}")
        ->assertOk();

    $entry = RecordAccessLog::query()->sole();

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->subject?->is($this->record))->toBeTrue()
        ->and($entry->action)->toBe(AccessAction::View)
        ->and($entry->context)->toBe('search')
        ->and($entry->ip_address)->toBe('10.2.3.4')
        ->and($this->record->accessLogs()->count())->toBe(1);
});

test('every access action can be recorded', function (AccessAction $action) {
    $this->record->recordAccess($action);

    expect(RecordAccessLog::query()->sole()->action)->toBe($action);
})->with(AccessAction::cases());

test('the database rejects updates, deletes and truncation of the record access log', function () {
    $this->record->recordAccess();

    expect(fn () => DB::transaction(fn () => DB::table('record_access_logs')->update(['action' => 'export'])))
        ->toThrow(QueryException::class, 'record_access_logs is append-only: UPDATE')
        ->and(fn () => DB::transaction(fn () => DB::table('record_access_logs')->delete()))
        ->toThrow(QueryException::class, 'record_access_logs is append-only: DELETE')
        ->and(fn () => DB::transaction(fn () => DB::statement('TRUNCATE record_access_logs')))
        ->toThrow(QueryException::class, 'record_access_logs is append-only: TRUNCATE');

    expect(RecordAccessLog::query()->count())->toBe(1);
});

test('the model refuses updates and deletes', function () {
    $entry = $this->record->recordAccess();

    expect(fn () => $entry->update(['context' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});
