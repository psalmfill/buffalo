<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8B.4: malware scan state of evidence files. Defaults to
 * `quarantined` (fail closed): files stored before scanning existed are
 * scanned by `buffalo:malware:scan-quarantined` (scheduled) before they are
 * served again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_evidence', function (Blueprint $table): void {
            $table->string('scan_status', 20)->default('quarantined');
            $table->timestampTz('scanned_at')->nullable();
            $table->index('scan_status');
        });

        DB::statement("ALTER TABLE incident_evidence ADD CONSTRAINT incident_evidence_scan_status_check CHECK (scan_status IN ('clean', 'quarantined', 'infected'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE incident_evidence DROP CONSTRAINT incident_evidence_scan_status_check');

        Schema::table('incident_evidence', function (Blueprint $table): void {
            $table->dropIndex(['scan_status']);
            $table->dropColumn(['scan_status', 'scanned_at']);
        });
    }
};
