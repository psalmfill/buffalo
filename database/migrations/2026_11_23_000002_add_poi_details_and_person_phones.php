<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\CipherSweet;
use ParagonIE\CipherSweet\EncryptedRow;

/**
 * Phase 11G.4: person-of-interest details, and several phone numbers per
 * person.
 *
 * - persons_of_interest: criminal record (rich text), height, origin
 *   (state, LGA within it, hometown), marital status, hair and eye colour,
 *   tribal marks, and two special categories stored encrypted with NO blind
 *   index (religion, political affiliations), so they can never be
 *   searched, filtered or matched on. Origin never affects jurisdiction.
 * - person_phones: every number encrypted with a blind index (exact
 *   matching), with a label and one primary. Each person's existing number
 *   moves here as the primary, re-encrypted for its new table, and
 *   persons.phone (with its blind index) is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons_of_interest', function (Blueprint $table) {
            $table->text('criminal_record')->nullable();
            $table->text('criminal_record_text')->nullable();
            $table->smallInteger('height_cm')->nullable();
            $table->foreignId('origin_state_id')->nullable()->constrained('states')->restrictOnDelete();
            $table->foreignId('origin_lga_id')->nullable()->constrained('lgas')->restrictOnDelete();
            $table->string('hometown', 255)->nullable();
            $table->string('marital_status', 12)->nullable();
            $table->text('religion')->nullable();
            $table->text('political_affiliations')->nullable();
            $table->string('hair_colour', 12)->nullable();
            $table->string('eye_colour', 12)->nullable();
            $table->string('has_tribal_marks', 8)->nullable();
            $table->string('tribal_marks_description', 500)->nullable();

            // The origin LGA belongs to the origin state.
            $table->foreign(['origin_lga_id', 'origin_state_id'], 'persons_of_interest_origin_lga_state_foreign')
                ->references(['id', 'state_id'])->on('lgas')->restrictOnDelete()->restrictOnUpdate();
        });

        DB::statement('ALTER TABLE persons_of_interest ADD CONSTRAINT persons_of_interest_height_range CHECK (height_cm IS NULL OR height_cm BETWEEN 50 AND 250)');
        DB::statement('ALTER TABLE persons_of_interest ADD CONSTRAINT persons_of_interest_origin_lga_needs_state CHECK (origin_lga_id IS NULL OR origin_state_id IS NOT NULL)');
        DB::statement("ALTER TABLE persons_of_interest ADD CONSTRAINT persons_of_interest_marital_status_valid CHECK (marital_status IS NULL OR marital_status IN ('single', 'married', 'divorced', 'separated', 'widowed', 'unknown'))");
        DB::statement("ALTER TABLE persons_of_interest ADD CONSTRAINT persons_of_interest_hair_colour_valid CHECK (hair_colour IS NULL OR hair_colour IN ('black', 'brown', 'grey', 'white', 'dyed', 'bald', 'other', 'unknown'))");
        DB::statement("ALTER TABLE persons_of_interest ADD CONSTRAINT persons_of_interest_eye_colour_valid CHECK (eye_colour IS NULL OR eye_colour IN ('black', 'dark_brown', 'brown', 'hazel', 'grey', 'other', 'unknown'))");
        DB::statement("ALTER TABLE persons_of_interest ADD CONSTRAINT persons_of_interest_tribal_marks_valid CHECK (has_tribal_marks IS NULL OR has_tribal_marks IN ('yes', 'no', 'unknown'))");

        Schema::create('person_phones', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('person_id')->index()->constrained('persons')->restrictOnDelete();
            $table->text('number');
            $table->string('label', 10)->default('mobile');
            $table->boolean('is_primary')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // Never in clear (as persons' encrypted columns).
        DB::statement("ALTER TABLE person_phones ADD CONSTRAINT person_phones_number_encrypted CHECK (number ~ '^nacl:')");
        DB::statement("ALTER TABLE persons_of_interest ADD CONSTRAINT persons_of_interest_religion_encrypted CHECK (religion IS NULL OR religion ~ '^nacl:')");
        DB::statement("ALTER TABLE persons_of_interest ADD CONSTRAINT persons_of_interest_political_affiliations_encrypted CHECK (political_affiliations IS NULL OR political_affiliations ~ '^nacl:')");
        DB::statement("ALTER TABLE person_phones ADD CONSTRAINT person_phones_label_valid CHECK (label IN ('mobile', 'work', 'home', 'other'))");
        DB::statement('CREATE UNIQUE INDEX person_phones_one_primary ON person_phones (person_id) WHERE is_primary AND deleted_at IS NULL');

        $this->moveNumbers();

        DB::table('blind_indexes')->where('indexable_type', 'App\\Models\\Person')->where('name', 'phone_bidx')->delete();
        Schema::table('persons', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->text('phone')->nullable();
        });

        $this->restoreNumbers();

        DB::table('blind_indexes')->where('indexable_type', 'App\\Models\\PersonPhone')->delete();
        Schema::dropIfExists('person_phones');

        Schema::table('persons_of_interest', function (Blueprint $table) {
            $table->dropForeign('persons_of_interest_origin_lga_state_foreign');
            $table->dropConstrainedForeignId('origin_state_id');
            $table->dropConstrainedForeignId('origin_lga_id');
            $table->dropColumn([
                'criminal_record', 'criminal_record_text', 'height_cm', 'hometown', 'marital_status', 'religion',
                'political_affiliations', 'hair_colour', 'eye_colour', 'has_tribal_marks', 'tribal_marks_description',
            ]);
        });
    }

    /** Each person's number becomes their primary phone (re-encrypted for person_phones). */
    private function moveNumbers(): void
    {
        $engine = app(CipherSweet::class);
        $persons = (new EncryptedRow($engine, 'persons'))->addOptionalTextField('phone');
        $phones = (new EncryptedRow($engine, 'person_phones'))->addTextField('number');
        $phones->addBlindIndex('number', new BlindIndex('person_phone_bidx', [], 256, true));

        DB::table('persons')->whereNotNull('phone')->orderBy('id')->select(['id', 'phone', 'created_by'])
            ->chunkById(500, function ($rows) use ($persons, $phones): void {
                foreach ($rows as $row) {
                    $number = $persons->decryptRow(['phone' => $row->phone])['phone'] ?? null;

                    if (! is_string($number) || $number === '') {
                        continue;
                    }

                    [$encrypted, $indexes] = $phones->prepareRowForStorage(['number' => $number]);
                    $index = $indexes['person_phone_bidx'];
                    $id = DB::table('person_phones')->insertGetId([
                        'ulid' => strtolower((string) Str::ulid()),
                        'person_id' => $row->id,
                        'number' => $encrypted['number'],
                        'label' => 'mobile',
                        'is_primary' => true,
                        'created_by' => $row->created_by,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('blind_indexes')->insert([
                        'indexable_type' => 'App\\Models\\PersonPhone',
                        'indexable_id' => $id,
                        'name' => 'person_phone_bidx',
                        'value' => is_array($index) ? $index['value'] : $index,
                    ]);
                }
            });
    }

    /** Down: the primary number returns to persons.phone (other numbers are dropped). */
    private function restoreNumbers(): void
    {
        $engine = app(CipherSweet::class);
        $persons = (new EncryptedRow($engine, 'persons'))->addOptionalTextField('phone');
        $persons->addBlindIndex('phone', new BlindIndex('phone_bidx', [], 256, true));
        $phones = (new EncryptedRow($engine, 'person_phones'))->addTextField('number');

        foreach (DB::table('person_phones')->where('is_primary', true)->whereNull('deleted_at')->get(['person_id', 'number']) as $row) {
            $number = $phones->decryptRow(['number' => $row->number])['number'] ?? null;

            if (! is_string($number)) {
                continue;
            }

            [$encrypted, $indexes] = $persons->prepareRowForStorage(['phone' => $number]);
            $index = $indexes['phone_bidx'];
            DB::table('persons')->where('id', $row->person_id)->update(['phone' => $encrypted['phone']]);
            DB::table('blind_indexes')->upsert([
                'indexable_type' => 'App\\Models\\Person', 'indexable_id' => $row->person_id, 'name' => 'phone_bidx',
                'value' => is_array($index) ? $index['value'] : $index,
            ], ['indexable_type', 'indexable_id', 'name'], ['value']);
        }
    }
};
