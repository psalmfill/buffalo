<?php

use App\Http\Presenters\IncidentTimeline;
use App\Models\Incident;
use App\Models\User;
use App\Support\Audit\AuditChain;
use App\Support\Database\MigrationConnection;
use App\Support\Jurisdiction\JurisdictionContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 11B.3: the data migration. Runs the migration down and up again as
| buffalo_owner inside a transaction that is rolled back afterwards, with
| rows written as they were before 11B (plain text) in between.
*/

const LEGACY_TEXT = "Gunmen stopped the bus at dawn.\nTwo passengers taken.\n\nThe driver escaped <unhurt> & reported it.";

test('the migration converts mutable rows, never rewrites append-only rows, and every audit chain still verifies', function () {
    $owner = DB::connection(MigrationConnection::NAME);
    $default = DB::getDefaultConnection();
    $migration = require database_path('migrations/2026_11_17_000001_add_rich_text.php');

    // The test's own transaction (as buffalo_app) holds the roles seeded for
    // it; it is dropped so that buffalo_owner can alter tables and seed them
    // again inside its own transaction, which is rolled back at the end.
    DB::connection($default)->rollBack();
    $owner->beginTransaction();
    DB::setDefaultConnection(MigrationConnection::NAME);

    try {
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        CrossFixtures::system();
        $world = JurisdictionWorld::build();
        $chief = $world->users['lga_chief'];
        $lga = $world->lgas['A1a'];
        $open = W::draft($lga, $chief);
        $closed = W::draft($lga, $chief);

        // Back to the schema before Phase 11B.
        $migration->down();

        // Rows as they were written before 11B: plain text everywhere.
        DB::table('incidents')->whereIn('id', [$open->id, $closed->id])->update(['description' => LEGACY_TEXT, 'action_taken' => "Police informed.\nRoadblock set up."]);
        DB::table('incidents')->where('id', $closed->id)->update(['status' => 'closed', 'reference_no' => 'BUF/T/TST/2026/000001', 'submitted_at' => now()]);
        $updateId = DB::table('incident_updates')->insertGetId([
            'ulid' => strtolower((string) Str::ulid()), 'incident_id' => $open->id, 'state_id' => $open->state_id, 'lga_id' => $open->lga_id,
            'type' => 'follow_up', 'body' => LEGACY_TEXT, 'classification' => 'restricted', 'created_by' => $chief->id, 'created_at' => now(),
        ]);
        $legacyUpdate = DB::table('incident_updates')->where('id', $updateId)->first();

        $tables = ['incident_updates', 'flashpoint_assessments', 'incident_status_histories', 'activity_log'];
        foreach ($tables as $table) {
            expect(AuditChain::verify($table)['ok'])->toBeTrue("{$table} verifies before the migration");
        }

        $migration->up();

        // Mutable rows: converted, line breaks and paragraphs kept, escaped; the plain copy filled.
        $expected = '<p>Gunmen stopped the bus at dawn.<br />Two passengers taken.</p><p>The driver escaped &lt;unhurt&gt; &amp; reported it.</p>';
        foreach ([$open, $closed] as $incident) {
            $row = DB::table('incidents')->where('id', $incident->id)->first();
            expect($row->description)->toBe($expected)
                ->and($row->description_text)->toBe(LEGACY_TEXT)
                ->and($row->action_taken)->toBe('<p>Police informed.<br />Roadblock set up.</p>')
                ->and($row->action_taken_text)->toBe("Police informed.\nRoadblock set up.");
        }
        // The closed incident is still locked after the conversion.
        expect(fn () => $owner->transaction(fn () => DB::table('incidents')->where('id', $closed->id)->update(['title' => 'Changed'])))->toThrow(QueryException::class);

        // Append-only rows: byte for byte as before, format NULL (plain text).
        $after = DB::table('incident_updates')->where('id', $updateId)->first();
        expect($after->body)->toBe($legacyUpdate->body)
            ->and($after->row_hash)->toBe($legacyUpdate->row_hash)
            ->and($after->body_format)->toBeNull()
            ->and($after->body_text)->toBeNull();

        foreach ($tables as $table) {
            expect(AuditChain::verify($table)['ok'])->toBeTrue("{$table} verifies after the migration");
        }

        // Old follow-ups display as before (plain text); new ones are HTML and chain on.
        $user = User::query()->findOrFail($chief->id);
        $incident = Incident::query()->findOrFail($open->id);
        W::step($user, $incident, 'updates', ['type' => 'follow_up', 'body' => '<p><strong>New</strong> update</p>', 'classification' => 'restricted'])->assertSessionHasNoErrors();
        CrossFixtures::system();
        $entries = collect(app(JurisdictionContext::class)->actingAs($user, fn () => IncidentTimeline::for($incident->fresh() ?? $incident)))->where('kind', 'update_follow_up')->values();

        expect($entries->firstWhere('format', 'text')['body'] ?? null)->toBe(LEGACY_TEXT)
            ->and($entries->firstWhere('format', 'html')['body'] ?? null)->toBe('<p><strong>New</strong> update</p>')
            ->and(AuditChain::verify('incident_updates')['ok'])->toBeTrue();
    } finally {
        DB::setDefaultConnection($default);
        $owner->rollBack();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
});
