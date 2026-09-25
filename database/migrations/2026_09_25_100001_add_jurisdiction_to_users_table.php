<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * BUFFALO user attributes: ULID, service details, jurisdiction, account
 * state and audit columns.
 *
 * Users created before this migration (starter-kit registrations on
 * development machines) get a ULID and the `system` level. They have no
 * role, so the jurisdiction scope and every policy deny them everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('ulid')->nullable()->after('id');
            $table->string('phone', 50)->nullable()->after('email');
            $table->string('service_number', 100)->nullable()->unique()->after('phone');
            $table->string('rank', 100)->nullable()->after('service_number');

            $table->string('jurisdiction_level', 20)->nullable()->after('rank');
            $table->foreignId('state_id')->nullable()->after('jurisdiction_level')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->nullable()->after('state_id')->index()->constrained()->restrictOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivation_reason', 1000)->nullable();
            $table->foreignId('deactivated_by')->nullable()->index()->constrained('users')->restrictOnDelete();

            $table->integer('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->foreignId('created_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->softDeletes();
        });

        foreach (DB::table('users')->whereNull('ulid')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->update(['ulid' => Str::lower((string) Str::ulid())]);
        }

        DB::table('users')->whereNull('jurisdiction_level')->update(['jurisdiction_level' => 'system']);

        Schema::table('users', function (Blueprint $table) {
            $table->ulid('ulid')->nullable(false)->change();
            $table->unique('ulid');
            $table->string('jurisdiction_level', 20)->nullable(false)->change();

            // An LGA-level user's LGA must be in the user's state.
            $table->foreign(['lga_id', 'state_id'], 'users_lga_same_state_foreign')
                ->references(['id', 'state_id'])
                ->on('lgas')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // Jurisdiction consistency: which of state_id / lga_id must be set
        // depends on the level. Any other level value is rejected too.
        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_jurisdiction_consistency_check CHECK (
                (jurisdiction_level IN ('system', 'national') AND state_id IS NULL AND lga_id IS NULL)
                OR (jurisdiction_level = 'state' AND state_id IS NOT NULL AND lga_id IS NULL)
                OR (jurisdiction_level = 'lga' AND state_id IS NOT NULL AND lga_id IS NOT NULL)
            )
            SQL);

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_failed_login_count_check CHECK (failed_login_count >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_failed_login_count_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_jurisdiction_consistency_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_lga_same_state_foreign');
            $table->dropUnique(['ulid']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropConstrainedForeignId('lga_id');
            $table->dropConstrainedForeignId('state_id');
            $table->dropUnique(['service_number']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'ulid', 'phone', 'service_number', 'rank', 'jurisdiction_level',
                'is_active', 'deactivated_at', 'deactivation_reason',
                'failed_login_count', 'locked_until', 'password_changed_at',
                'last_login_at', 'last_login_ip',
            ]);
        });
    }
};
