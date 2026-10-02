<?php

namespace Tests\Support\Audit;

use App\Models\Concerns\LogsReads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only access-logged model; its table exists only inside the test
 * transaction.
 */
class AccessLoggedFixture extends Model
{
    use LogsReads;

    protected $table = 'access_logged_fixtures';

    protected $guarded = [];

    /**
     * Created once per run as buffalo_owner, outside the test transaction
     * (Phase 8A: the application role cannot create tables).
     */
    public static function createTable(): void
    {
        $schema = Schema::connection('pgsql_migrations');

        if ($schema->hasTable('access_logged_fixtures')) {
            return;
        }

        $schema->create('access_logged_fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->timestamps();
        });
    }
}
