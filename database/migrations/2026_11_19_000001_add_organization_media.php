<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization logos and site photos (Phase 11D).
 *
 * - `media.deleted_at`: media becomes soft-deletable, so a replaced or
 *   removed logo (or a deleted site photo's file) is kept in history. The
 *   media library keeps the files of soft-deleted media.
 * - `organization_site_photos`: one row per photo, with the file attached
 *   as media. Its place is copied from the site and bound to it by a
 *   cascading composite FK (access rule 9), so it follows a change of the
 *   site's LGA. Classification is the site's, never its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->timestampTz('deleted_at')->nullable()->index();
        });

        Schema::create('organization_site_photos', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_site_id')->index();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->string('caption', 200)->nullable();
            $table->date('taken_on')->nullable();
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            // The photo is in its site's LGA and state.
            $table->foreign(['organization_site_id', 'lga_id', 'state_id'], 'organization_site_photos_same_site_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('organization_sites')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign(['lga_id', 'state_id'], 'organization_site_photos_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement("ALTER TABLE organization_site_photos ADD CONSTRAINT organization_site_photos_caption_not_blank CHECK (caption IS NULL OR btrim(caption) <> '')");
        DB::statement('CREATE UNIQUE INDEX organization_site_photos_one_cover ON organization_site_photos (organization_site_id) WHERE is_cover AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_site_photos');

        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }
};
