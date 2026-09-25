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

    public static function createTable(): void
    {
        Schema::create('access_logged_fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->timestamps();
        });
    }
}
