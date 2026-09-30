<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizations (Phase 4B).
 *
 * - organization_sectors: a lookup table in the Phase 2A pattern (shared
 *   columns, code format, DELETE/TRUNCATE rejected).
 * - organizations: a NATIONAL DIRECTORY entry. Non-sensitive identity data
 *   only; deliberately no location columns, so it is not jurisdiction-scoped.
 * - organization_sites: physical locations; jurisdiction-scoped (composite
 *   FKs) and classified; at most one head office per organization; the
 *   location lies in the LGA (Phase 2 trigger function).
 * - organization_site_formations: government security protecting a site.
 * - organization_contacts: per site, with their own state_id/lga_id kept
 *   equal to the site's (composite FK; access rule 9). Phone and email are
 *   CipherSweet ciphertext only (CHECKs); at most one primary per site.
 */
return new class extends Migration
{
    private const CIPHERTEXT = "'^(nacl|fips|brng):[A-Za-z0-9_=-]+$'";

    public function up(): void
    {
        Schema::create('organization_sectors', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->string('code', 64);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('code', 'organization_sectors_code_unique');
        });

        DB::statement("ALTER TABLE organization_sectors ADD CONSTRAINT organization_sectors_code_format CHECK (code ~ '^[a-z][a-z0-9_]*$')");
        DB::statement('CREATE TRIGGER organization_sectors_no_delete BEFORE DELETE ON organization_sectors FOR EACH ROW EXECUTE FUNCTION buffalo_reject_reference_delete()');
        DB::statement('CREATE TRIGGER organization_sectors_no_truncate BEFORE TRUNCATE ON organization_sectors FOR EACH STATEMENT EXECUTE FUNCTION buffalo_reject_reference_delete()');

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name', 255);
            $table->jsonb('other_names')->default(DB::raw("'[]'::jsonb"));
            $table->string('type', 32);
            $table->foreignId('organization_sector_id')->index()->constrained()->restrictOnDelete();
            $table->string('rc_number', 32)->nullable();
            $table->string('website', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_type_valid CHECK (type IN ('company', 'ngo', 'government_agency', 'international_organization', 'association_union', 'educational_institution', 'other'))");
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_status_valid CHECK (status IN ('active', 'dormant', 'closed'))");
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_name_not_blank CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_other_names_array CHECK (jsonb_typeof(other_names) = 'array')");
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_rc_number_format CHECK (rc_number IS NULL OR rc_number ~ '^[A-Z0-9]+$')");
        DB::statement("ALTER TABLE organizations ADD COLUMN search_name text GENERATED ALWAYS AS (buffalo_search_text(name || ' ' || other_names::text)) STORED");
        DB::statement('CREATE INDEX organizations_search_name_trgm ON organizations USING gin (search_name gin_trgm_ops)');
        DB::statement('CREATE UNIQUE INDEX organizations_rc_number_unique ON organizations (rc_number) WHERE rc_number IS NOT NULL AND deleted_at IS NULL');

        Schema::create('organization_sites', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('site_type', 32);
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('community_id')->nullable()->index();
            $table->text('address')->nullable();
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->integer('employees_estimate')->nullable();
            $table->string('security_relevance', 32);
            $table->boolean('has_private_security')->default(false);
            $table->foreignId('security_provider_organization_id')->nullable()->index()->constrained('organizations')->restrictOnDelete();
            $table->text('security_notes')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('classification', 16)->default('restricted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['lga_id', 'state_id'], 'organization_sites_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['community_id', 'lga_id'], 'organization_sites_community_same_lga_foreign')
                ->references(['id', 'lga_id'])->on('communities')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['id', 'lga_id', 'state_id'], 'organization_sites_id_lga_state_unique');
            $table->spatialIndex('location');
            $table->index(['security_relevance', 'status']);
        });

        DB::statement("ALTER TABLE organization_sites ADD CONSTRAINT organization_sites_type_valid CHECK (site_type IN ('head_office', 'regional_office', 'branch', 'facility', 'plant', 'terminal', 'depot', 'field_camp', 'warehouse', 'other'))");
        DB::statement("ALTER TABLE organization_sites ADD CONSTRAINT organization_sites_relevance_valid CHECK (security_relevance IN ('low', 'medium', 'high', 'critical_infrastructure'))");
        DB::statement("ALTER TABLE organization_sites ADD CONSTRAINT organization_sites_status_valid CHECK (status IN ('active', 'inactive', 'closed'))");
        DB::statement("ALTER TABLE organization_sites ADD CONSTRAINT organization_sites_classification_valid CHECK (classification IN ('restricted', 'confidential', 'secret'))");
        DB::statement('ALTER TABLE organization_sites ADD CONSTRAINT organization_sites_employees_non_negative CHECK (employees_estimate IS NULL OR employees_estimate >= 0)');
        DB::statement("ALTER TABLE organization_sites ADD CONSTRAINT organization_sites_name_not_blank CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE organization_sites ADD CONSTRAINT organization_sites_provider_not_self CHECK (security_provider_organization_id IS NULL OR security_provider_organization_id <> organization_id)');
        DB::statement("CREATE UNIQUE INDEX organization_sites_one_head_office ON organization_sites (organization_id) WHERE site_type = 'head_office' AND deleted_at IS NULL");
        DB::statement('CREATE TRIGGER organization_sites_location_in_lga BEFORE INSERT OR UPDATE OF location, lga_id ON organization_sites FOR EACH ROW EXECUTE FUNCTION buffalo_community_location_in_lga()');

        Schema::create('organization_site_formations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_site_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('agency_formation_id')->index()->constrained()->restrictOnDelete();
            $table->string('arrangement', 32);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_site_id', 'agency_formation_id'], 'organization_site_formations_unique');
        });

        DB::statement("ALTER TABLE organization_site_formations ADD CONSTRAINT organization_site_formations_arrangement_valid CHECK (arrangement IN ('stationed_on_site', 'regular_patrol', 'on_call', 'other'))");

        Schema::create('organization_contacts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_site_id')->index();
            $table->foreignId('state_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('lga_id')->index()->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('position', 255);
            $table->text('phone')->nullable();
            $table->text('email')->nullable();
            $table->boolean('is_management')->default(false);
            $table->boolean('is_security_contact')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->foreignId('person_id')->nullable()->index()->constrained('persons')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The contact is in its site's LGA and state.
            $table->foreign(['organization_site_id', 'lga_id', 'state_id'], 'organization_contacts_same_site_place_foreign')
                ->references(['id', 'lga_id', 'state_id'])->on('organization_sites')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign(['lga_id', 'state_id'], 'organization_contacts_lga_same_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
        });

        foreach (['phone', 'email'] as $column) {
            DB::statement("ALTER TABLE organization_contacts ADD CONSTRAINT organization_contacts_{$column}_encrypted CHECK ({$column} IS NULL OR {$column} ~ ".self::CIPHERTEXT.')');
        }

        DB::statement("ALTER TABLE organization_contacts ADD CONSTRAINT organization_contacts_name_not_blank CHECK (btrim(name) <> '' AND btrim(position) <> '')");
        DB::statement('CREATE UNIQUE INDEX organization_contacts_one_primary ON organization_contacts (organization_site_id) WHERE is_primary AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_contacts');
        Schema::dropIfExists('organization_site_formations');
        Schema::dropIfExists('organization_sites');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('organization_sectors');
    }
};
