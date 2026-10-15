<?php

use App\Support\RichText\RichText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Rich text (Phase 11B).
 *
 * Mutable tables: each rich field gets a plain text copy `<field>_text`,
 * and existing plain text is converted to HTML (escaped, paragraphs in
 * <p>, single line breaks as <br>) through the sanitiser, in batches. The
 * lock triggers of closed/invalid incidents and reviewed coverage reports
 * are disabled for this conversion only (inside this transaction): the
 * content is unchanged, only its format. No activity events are written;
 * rows converted per table are printed (and recorded in the handover).
 *
 * Append-only, hash-chained tables (incident_updates,
 * flashpoint_assessments) are never rewritten: they get a nullable
 * `body_format` and nullable plain text copies. Existing rows keep NULL
 * (shown as plain text, as before); new rows are 'html'. The chain's
 * canonical row strips nulls (buffalo_audit_canonical), so existing rows
 * hash exactly as before and every chain still verifies.
 *
 * Search vectors built from rich fields now use the plain text copies, so
 * markup is never indexed.
 */
return new class extends Migration
{
    /** Mutable tables: table => rich fields. */
    private const MUTABLE = [
        'incidents' => ['description', 'action_taken', 'resolution_summary'],
        'lga_profiles' => ['history', 'economic_activities', 'major_roads', 'waterways', 'borders', 'general_notes'],
        'communities' => ['notes'],
        'religious_institutions' => ['notes'],
        'community_security_notes' => ['description'],
        'persons' => ['description'],
        'persons_of_interest' => ['known_offences', 'modus_operandi'],
        'criminal_groups' => ['description'],
        'agency_formations' => ['notes'],
        'coverage_reports' => ['summary', 'gaps_identified', 'recommendations'],
        'organizations' => ['description'],
        'organization_sites' => ['security_notes'],
        'flashpoints' => ['description', 'recommended_action'],
        'threat_reports' => ['description', 'recommended_action', 'target_description'],
    ];

    /** Append-only tables: table => rich fields (never rewritten). */
    private const APPEND_ONLY = [
        'incident_updates' => ['body'],
        'flashpoint_assessments' => ['assessment', 'recommended_action'],
    ];

    /** Lock triggers lifted for the conversion only. */
    private const LOCKS = ['incidents' => 'incidents_locked', 'coverage_reports' => 'coverage_reports_locked'];

    /** Generated search vectors: table => [old expression, new expression]. */
    private const VECTORS = [
        'incidents' => [
            "to_tsvector('english', coalesce(title, '') || ' ' || coalesce(description, ''))",
            "to_tsvector('english', coalesce(title, '') || ' ' || coalesce(description_text, ''))",
        ],
        'flashpoints' => [
            "setweight(to_tsvector('english', coalesce(name, '')), 'A') || setweight(to_tsvector('english', coalesce(description, '')), 'B')",
            "setweight(to_tsvector('english', coalesce(name, '')), 'A') || setweight(to_tsvector('english', coalesce(description_text, '')), 'B')",
        ],
        'threat_reports' => [
            "setweight(to_tsvector('english', coalesce(title, '')), 'A') || setweight(to_tsvector('english', coalesce(description, '')), 'B')",
            "setweight(to_tsvector('english', coalesce(title, '')), 'A') || setweight(to_tsvector('english', coalesce(description_text, '')), 'B')",
        ],
    ];

    private const BATCH = 500;

    public function up(): void
    {
        foreach (self::MUTABLE as $table => $fields) {
            Schema::table($table, function (Blueprint $t) use ($fields) {
                foreach ($fields as $field) {
                    $t->text("{$field}_text")->nullable();
                }
            });
        }

        foreach (self::APPEND_ONLY as $table => $fields) {
            Schema::table($table, function (Blueprint $t) use ($fields) {
                // NULL: a row written before Phase 11B (plain text).
                $t->string('body_format', 4)->nullable();
                foreach ($fields as $field) {
                    $t->text("{$field}_text")->nullable();
                }
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_body_format CHECK (body_format IN ('text', 'html'))");
        }

        foreach (self::LOCKS as $table => $trigger) {
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
        }

        $out = new ConsoleOutput;
        foreach (self::MUTABLE as $table => $fields) {
            $converted = $this->convert($table, $fields);
            if (! app()->runningUnitTests()) {
                $out->writeln(sprintf('  Rich text: %s, %d rows converted.', $table, $converted));
            }
        }

        foreach (self::LOCKS as $table => $trigger) {
            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
        }

        foreach (self::VECTORS as $table => [, $expression]) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN search_vector SET EXPRESSION AS ({$expression})");
        }
    }

    public function down(): void
    {
        foreach (self::VECTORS as $table => [$expression]) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN search_vector SET EXPRESSION AS ({$expression})");
        }

        foreach (self::LOCKS as $table => $trigger) {
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
        }

        // Back to plain text: the plain copy replaces the HTML.
        foreach (self::MUTABLE as $table => $fields) {
            DB::table($table)->update(array_combine($fields, array_map(fn (string $f) => DB::raw("{$f}_text"), $fields)));
            Schema::table($table, function (Blueprint $t) use ($fields) {
                $t->dropColumn(array_map(fn (string $f) => "{$f}_text", $fields));
            });
        }

        foreach (self::LOCKS as $table => $trigger) {
            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
        }

        // Append-only rows written as HTML since keep their HTML (rows are
        // never rewritten); only the new columns go.
        foreach (self::APPEND_ONLY as $table => $fields) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_body_format");
            Schema::table($table, function (Blueprint $t) use ($fields) {
                $t->dropColumn(['body_format', ...array_map(fn (string $f) => "{$f}_text", $fields)]);
            });
        }
    }

    /**
     * @param  list<string>  $fields
     */
    private function convert(string $table, array $fields): int
    {
        $converted = 0;

        DB::table($table)
            ->where(fn ($q) => array_map(fn (string $f) => $q->orWhereNotNull($f), $fields))
            ->orderBy('id')
            ->select(['id', ...$fields])
            ->chunkById(self::BATCH, function ($rows) use ($table, $fields, &$converted) {
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($fields as $field) {
                        $rich = RichText::fromPlainText($row->{$field});
                        $values[$field] = $rich?->html;
                        $values["{$field}_text"] = $rich?->text;
                    }
                    DB::table($table)->where('id', $row->id)->update($values);
                    $converted++;
                }
            });

        return $converted;
    }
};
