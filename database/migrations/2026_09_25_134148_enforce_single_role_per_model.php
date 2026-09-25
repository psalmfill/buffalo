<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUFFALO users have exactly one role. App\Actions\Users\AssignRole syncs
 * (never adds) roles; this unique index makes a second role impossible at
 * the database level as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('permission.table_names.model_has_roles', 'model_has_roles'), function (Blueprint $table) {
            $table->unique(['model_id', 'model_type'], 'model_has_roles_one_role_per_model_unique');
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.model_has_roles', 'model_has_roles'), function (Blueprint $table) {
            $table->dropUnique('model_has_roles_one_role_per_model_unique');
        });
    }
};
