<?php

namespace Tests\Support\Jurisdiction;

use App\Models\Concerns\HasJurisdiction;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only jurisdiction-scoped model. Its table is created inside each
 * test's transaction (PostgreSQL DDL is transactional), so it never exists
 * in production migrations.
 *
 * @property int $id
 * @property string $label
 * @property int $state_id
 * @property int|null $lga_id
 */
class ScopedFixtureRecord extends Model implements JurisdictionScoped
{
    use HasJurisdiction;

    protected $table = 'scoped_fixture_records';

    protected $guarded = [];

    /**
     * Created once per test run as buffalo_owner (Phase 8A: the
     * application role cannot create tables), outside the test
     * transaction; migrate:fresh drops it at the start of the next run.
     * Default privileges give buffalo_app its rows.
     */
    public static function createTable(): void
    {
        $schema = Schema::connection('pgsql_migrations');

        if ($schema->hasTable('scoped_fixture_records')) {
            return;
        }

        $schema->create('scoped_fixture_records', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->foreignId('state_id')->constrained();
            $table->foreignId('lga_id')->nullable()->constrained();
            $table->timestamps();
        });
    }
}
