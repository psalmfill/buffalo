<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Posting history: who held which role, where, and when. Later records use
 * it to answer "who was the LGA chief of X on date Y"
 * (OfficerPosting::holderOn). At most one open posting per user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('officer_postings', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->string('role', 50);
            $table->string('jurisdiction_level', 20);
            $table->foreignId('state_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('position_title')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['lga_id', 'role', 'started_at']);
            $table->index(['state_id', 'role', 'started_at']);

            $table->foreign(['lga_id', 'state_id'], 'officer_postings_lga_same_state_foreign')
                ->references(['id', 'state_id'])
                ->on('lgas')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        DB::statement('CREATE UNIQUE INDEX officer_postings_one_open_per_user ON officer_postings (user_id) WHERE ended_at IS NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE officer_postings ADD CONSTRAINT officer_postings_jurisdiction_consistency_check CHECK (
                (jurisdiction_level IN ('system', 'national') AND state_id IS NULL AND lga_id IS NULL)
                OR (jurisdiction_level = 'state' AND state_id IS NOT NULL AND lga_id IS NULL)
                OR (jurisdiction_level = 'lga' AND state_id IS NOT NULL AND lga_id IS NOT NULL)
            )
            SQL);

        DB::statement('ALTER TABLE officer_postings ADD CONSTRAINT officer_postings_period_check CHECK (ended_at IS NULL OR ended_at >= started_at)');

        // Open a posting for every existing active user with a role.
        $users = DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')->where('model_has_roles.model_type', User::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->get(['users.id', 'users.jurisdiction_level', 'users.state_id', 'users.lga_id', 'users.created_at', 'roles.name as role']);

        foreach ($users as $user) {
            DB::table('officer_postings')->insert([
                'ulid' => Str::lower((string) Str::ulid()),
                'user_id' => $user->id,
                'role' => $user->role,
                'jurisdiction_level' => $user->jurisdiction_level,
                'state_id' => $user->state_id,
                'lga_id' => $user->lga_id,
                'started_at' => $user->created_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('officer_postings');
    }
};
