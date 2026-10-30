<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hierarchy administration (CRUD coverage step): districts, LGAs and wards
 * may now also be created in Administration › Hierarchy. `origin` tells
 * screen-created entries ("admin") from imported ones ("import"), so the
 * CSV import can report screen-created entries missing from the official
 * files instead of deactivating them. Who created an entry, and why, is
 * kept on the row for that report (the audit log holds the full history).
 */
return new class extends Migration
{
    private const TABLES = ['senatorial_districts', 'lgas', 'wards'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('origin', 10)->default('import');
                $blueprint->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $blueprint->text('created_reason')->nullable();
            });

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_origin CHECK (origin IN ('import', 'admin'))");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_origin");

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('created_by');
                $blueprint->dropColumn(['origin', 'created_reason']);
            });
        }
    }
};
