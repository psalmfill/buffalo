<?php

use App\Actions\Evidence\ScanQuarantinedEvidence;
use App\Actions\Evidence\VerifyEvidenceIntegrity;
use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Enums\IncidentStatus;
use App\Jobs\ProcessEvidenceJob;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\EvidenceFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    Storage::fake('evidence');
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
});

function uploadEvidence(User $user, Incident $incident, UploadedFile $file, array $overrides = []): TestResponse
{
    return CrossFixtures::signIn($user)->postJson("/incidents/{$incident->ulid}/evidence", [
        'file' => $file,
        'title' => 'Scene photo',
        'description' => null,
        'classification' => 'restricted',
        ...$overrides,
    ]);
}

function storedPath(IncidentEvidence $evidence, string $conversion = ''): string
{
    return Storage::disk('evidence')->path((string) $evidence->original()?->getPathRelativeToRoot($conversion));
}

function lastEvidence(): IncidentEvidence
{
    CrossFixtures::system();

    return IncidentEvidence::query()->withCoordinates()->latest('id')->firstOrFail();
}

test('accepted types are detected from their content', function (Closure $file, EvidenceType $type) {
    uploadEvidence($this->chief, $this->incident, $file())->assertCreated()->assertJsonPath('evidence.type.value', $type->value);

    expect(lastEvidence()->evidence_type)->toBe($type);
})->with([
    'jpeg' => [fn () => EvidenceFixtures::photo(), EvidenceType::Image],
    'png' => [fn () => EvidenceFixtures::png(), EvidenceType::Image],
    'pdf' => [fn () => EvidenceFixtures::pdf(), EvidenceType::Document],
    'docx' => [fn () => EvidenceFixtures::docx(), EvidenceType::Document],
    'xlsx' => [fn () => EvidenceFixtures::xlsx(), EvidenceType::Document],
    'text' => [fn () => EvidenceFixtures::text(), EvidenceType::Document],
    'mp4' => [fn () => EvidenceFixtures::mp4(), EvidenceType::Video],
    'webm' => [fn () => EvidenceFixtures::webm(), EvidenceType::Video],
    'mp3' => [fn () => EvidenceFixtures::mp3(), EvidenceType::Audio],
    'm4a' => [fn () => EvidenceFixtures::m4a(), EvidenceType::Audio],
    'ogg' => [fn () => EvidenceFixtures::ogg(), EvidenceType::Audio],
    'wav' => [fn () => EvidenceFixtures::wav(), EvidenceType::Audio],
]);

test('renamed executables, scripts, mismatched extensions, other types and oversize files are refused', function (Closure $file) {
    uploadEvidence($this->chief, $this->incident, $file())->assertUnprocessable()->assertJsonValidationErrors('file');

    expect(IncidentEvidence::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'executable named .jpg' => [fn () => EvidenceFixtures::renamedExecutable()],
    'php named .txt' => [fn () => EvidenceFixtures::renamedScript()],
    'pdf named .jpg' => [fn () => EvidenceFixtures::pdf('photo.jpg')],
    'jpeg named .pdf' => [fn () => EvidenceFixtures::photo(name: 'statement.pdf')],
    'svg' => [fn () => UploadedFile::fake()->createWithContent('map.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')],
    'html' => [fn () => UploadedFile::fake()->createWithContent('page.html', '<!DOCTYPE html><html><body>x</body></html>')],
    'zip' => [fn () => UploadedFile::fake()->create('archive.zip', 10, 'application/zip')],
    'empty' => [fn () => UploadedFile::fake()->createWithContent('empty.txt', '')],
]);

test('files over the limit for their type are refused', function () {
    config(['buffalo.evidence.max_mb.video' => 1]);

    uploadEvidence($this->chief, $this->incident, EvidenceFixtures::mp4(1024 * 1024 + 100))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'Video files may be at most 1 MB.']);
});

test('the stored original is byte-identical to the upload, with its SHA-256 recorded', function () {
    $file = EvidenceFixtures::photo();
    $bytes = (string) file_get_contents((string) $file->getRealPath());

    uploadEvidence($this->chief, $this->incident, $file, ['title' => 'Bus at the toll gate'])->assertCreated();
    $evidence = lastEvidence();

    expect(file_get_contents(storedPath($evidence)))->toBe($bytes)
        ->and($evidence->sha256)->toBe(hash('sha256', $bytes))
        ->and($evidence->size_bytes)->toBe(strlen($bytes))
        ->and($evidence->mime_type)->toBe('image/jpeg')
        ->and($evidence->original_filename)->toBe('IMG_2044.jpg')
        ->and($evidence->uploaded_by)->toBe($this->chief->id)
        ->and($evidence->isProcessed())->toBeTrue()
        // The uploader's file name is never the storage name.
        ->and($evidence->original()?->file_name)->toBe('original.jpg')
        ->and(storedPath($evidence))->not->toContain('IMG_2044');

    $created = Activity::query()->where('subject_type', $evidence->getMorphClass())->where('subject_id', $evidence->id)->where('event', 'created')->sole();
    expect($created->attribute_changes['attributes']['sha256'])->toBe($evidence->sha256);
});

test('EXIF capture time and GPS are extracted; previews carry no EXIF; the original keeps it', function () {
    uploadEvidence($this->chief, $this->incident, EvidenceFixtures::photo(latitude: 6.455, longitude: 3.3841, takenAt: '2026:09:30 14:05:10'))->assertCreated();
    $evidence = lastEvidence();

    expect($evidence->captured_at?->setTimezone('Africa/Lagos')->format('Y-m-d H:i:s'))->toBe('2026-09-30 14:05:10')
        ->and(round((float) $evidence->latitude, 4))->toBe(6.455)
        ->and(round((float) $evidence->longitude, 4))->toBe(3.3841)
        ->and($evidence->exif_summary)->toMatchArray(['make' => EvidenceFixtures::MARKER, 'model' => 'Pixel 8', 'datetime_original' => '2026:09:30 14:05:10']);

    // The original keeps its EXIF.
    expect(exif_read_data(storedPath($evidence))['Make'] ?? null)->toBe(EvidenceFixtures::MARKER);

    // Conversions (queue is sync in tests) have none.
    foreach (['thumb' => 300, 'preview' => 1600] as $conversion => $max) {
        $path = storedPath($evidence->fresh(), $conversion);
        $exif = @exif_read_data($path);
        [$width, $height] = (array) getimagesize($path);

        expect($evidence->original()?->hasGeneratedConversion($conversion))->toBeTrue()
            ->and((string) file_get_contents($path))->not->toContain(EvidenceFixtures::MARKER)
            ->and(is_array($exif) ? array_intersect_key($exif, array_flip(['Make', 'Model', 'GPSLatitude', 'DateTimeOriginal'])) : [])->toBe([])
            ->and(max($width, $height))->toBeLessThanOrEqual($max);
    }
});

test('documents, video and audio get no conversions', function () {
    uploadEvidence($this->chief, $this->incident, EvidenceFixtures::pdf())->assertCreated();

    expect(lastEvidence()->original()?->generated_conversions ?? [])->toBe([]);
});

test('large files are verified on the queue and show as processing until then', function () {
    config(['buffalo.evidence.queue_above_mb' => 0]);
    Queue::fake([ProcessEvidenceJob::class]);

    uploadEvidence($this->chief, $this->incident, EvidenceFixtures::mp4())->assertCreated()->assertJsonPath('evidence.processing', true);
    $evidence = lastEvidence();

    expect($evidence->isProcessed())->toBeFalse();
    Queue::assertPushed(ProcessEvidenceJob::class, fn (ProcessEvidenceJob $job) => $job->evidenceId === $evidence->id);

    (new ProcessEvidenceJob($evidence->id, $this->chief->id))->handle(app(JurisdictionContext::class), app(ScanQuarantinedEvidence::class), app(VerifyEvidenceIntegrity::class));
    expect($evidence->fresh()?->isProcessed())->toBeTrue();
});

test('classification: at least the incident level and within the uploader clearance', function () {
    $confidential = Incident::factory()->in($this->world->lgas['A1a'])->by($this->world->users['state_director'])->classified(Classification::Confidential)->create();
    $sd = $this->world->users['state_director'];

    uploadEvidence($sd, $confidential, EvidenceFixtures::pdf(), ['classification' => 'restricted'])
        ->assertUnprocessable()->assertJsonValidationErrors(['classification' => 'Evidence cannot be classified below its incident (Confidential).']);
    uploadEvidence($sd, $confidential, EvidenceFixtures::pdf(), ['classification' => 'secret'])->assertCreated();

    // An LGA chief cannot classify above their clearance.
    uploadEvidence($this->chief, $this->incident, EvidenceFixtures::pdf(), ['classification' => 'secret'])
        ->assertUnprocessable()->assertJsonValidationErrors('classification');

    // The database refuses an item below its incident, too.
    CrossFixtures::system();
    $evidence = IncidentEvidence::query()->where('incident_id', $confidential->id)->sole();
    expect(fn () => DB::transaction(fn () => DB::table('incident_evidence')->where('id', $evidence->id)->update(['classification' => 'restricted'])))
        ->toThrow(QueryException::class);
});

test('raising the incident raises its evidence, logged; the database refuses otherwise', function () {
    uploadEvidence($this->chief, $this->incident, EvidenceFixtures::pdf())->assertCreated();
    $evidence = lastEvidence();
    $sd = $this->world->users['state_director'];

    $payload = [
        'incident_type' => $this->incident->type->ulid,
        'details' => [],
        'title' => $this->incident->title,
        'description' => $this->incident->description,
        'severity' => 'medium',
        'occurred_at' => now()->subDay()->format('Y-m-d\TH:i'),
        'occurred_at_precision' => 'exact',
        'lga' => $this->world->lgas['A1a']->ulid,
        'classification' => 'secret',
        'casualties' => [], 'persons' => [], 'groups' => [], 'agencies' => [], 'properties' => [],
    ];
    CrossFixtures::signIn($sd)->put("/incidents/{$this->incident->ulid}", $payload)->assertSessionHasNoErrors();

    expect($evidence->fresh()?->classification)->toBe(Classification::Secret)
        ->and(Activity::query()->where('event', 'classification_changed')->where('subject_id', $evidence->id)->where('subject_type', $evidence->getMorphClass())->exists())->toBeTrue();

    // Without raising the items first, the incident cannot go above them.
    $other = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    uploadEvidence($this->chief, $other, EvidenceFixtures::pdf())->assertCreated();
    expect(fn () => DB::transaction(fn () => DB::table('incidents')->where('id', $other->id)->update(['classification' => 'secret'])))
        ->toThrow(QueryException::class);
});

test('uploading: an incident the user can update while a draft; any viewer once submitted, until closed', function () {
    $dsd = $this->world->users['deputy_state_director'];
    $dg = $this->world->users['director_general'];

    uploadEvidence($dsd, $this->incident, EvidenceFixtures::pdf())->assertCreated();
    uploadEvidence($dg, $this->incident, EvidenceFixtures::pdf())->assertForbidden();
    uploadEvidence($this->world->users['super_admin'], $this->incident, EvidenceFixtures::pdf())->assertNotFound();
    uploadEvidence(CrossFixtures::chiefOf($this->world->lgas['A1b']), $this->incident, EvidenceFixtures::pdf())->assertNotFound();

    CrossFixtures::system();
    $this->incident->forceFill(['status' => IncidentStatus::Submitted])->save();
    uploadEvidence($dg, $this->incident, EvidenceFixtures::pdf())->assertCreated();

    CrossFixtures::system();
    $this->incident->forceFill(['status' => IncidentStatus::Closed])->save();
    uploadEvidence($dg, $this->incident, EvidenceFixtures::pdf())->assertForbidden();
});
