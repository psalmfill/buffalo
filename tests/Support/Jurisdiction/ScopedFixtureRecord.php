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

    public static function createTable(): void
    {
        if (Schema::hasTable('scoped_fixture_records')) {
            return;
        }

        Schema::create('scoped_fixture_records', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->foreignId('state_id')->constrained();
            $table->foreignId('lga_id')->nullable()->constrained();
            $table->timestamps();
        });
    }
}
