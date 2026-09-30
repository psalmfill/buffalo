<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5A.0: until now the pgsql session ran in UTC while the application
 * wrote Africa/Lagos wall-clock values (without an offset), so timestamptz
 * columns were stored one hour late. The connection now uses the app time
 * zone; this corrects the values already stored. Nigeria has no daylight
 * saving: the shift is a constant hour.
 *
 * Every timestamptz column in the schema when this was written (all set by
 * the application, none by a database default):
 * - lga_profiles.last_reviewed_at
 * - persons_of_interest.last_reviewed_at
 * - persons_of_interest.last_sighted_at
 *
 * Plain `timestamp` columns hold correct Lagos wall-clock values and are
 * left as they are.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'lga_profiles' => ['last_reviewed_at'],
        'persons_of_interest' => ['last_reviewed_at', 'last_sighted_at'],
    ];

    public function up(): void
    {
        $this->shift('-');
    }

    public function down(): void
    {
        $this->shift('+');
    }

    private function shift(string $sign): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                // Literal identifiers from the constant above; no input.
                DB::statement("UPDATE {$table} SET {$column} = {$column} {$sign} interval '1 hour' WHERE {$column} IS NOT NULL");
            }
        }
    }
};
