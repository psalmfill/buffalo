<?php

use App\Actions\Taskings\SendTasking;
use App\Enums\EvidenceScanStatus;
use App\Models\Tasking;
use App\Models\TaskingAttachment;
use App\Models\TaskingRecipient;
use App\Models\User;
use App\Notifications\MalwareDetected;
use App\Rules\MalwareFree;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\EvidenceFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Malware\FakeMalwareScanner;
use Tests\Support\Taskings\TaskingFixtures;

/*
| Phase 11H.1: attachments on a tasking (while a draft) and on an LGA's
| report back, through the same service as security report attachments
| (Phase 11G): content detection, malware scan (fail closed), SHA-256,
| EXIF-free previews, the evidence disk (backed up), logging.
*/

beforeEach(function () {
    Notification::fake();
    Storage::fake('evidence');
    Storage::fake('quarantine');
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->sd = $this->world->users['state_director'];
    $this->a = $this->world->lgas['A1a'];
    $this->chief = TaskingFixtures::chief($this->a);
    $this->chiefB = TaskingFixtures::chief($this->world->lgas['A1b']);
    $this->admin = User::factory()->superAdmin()->create();
    $this->draft = TaskingFixtures::draft($this->sd);
});

function attachToTasking(UploadedFile $file, ?User $as = null, ?Tasking $tasking = null): TestResponse
{
    $tasking ??= test()->draft;

    return CrossFixtures::signIn($as ?? test()->sd)->postJson("/taskings/{$tasking->ulid}/attachments", ['file' => $file, 'caption' => 'Map of the roads']);
}

test('photos, videos and documents are accepted on a draft; audio and disguised files are refused', function () {
    foreach ([EvidenceFixtures::photo(), EvidenceFixtures::mp4(), EvidenceFixtures::pdf(), EvidenceFixtures::docx()] as $file) {
        attachToTasking($file)->assertCreated();
    }
    attachToTasking(EvidenceFixtures::mp3())->assertUnprocessable()->assertJsonPath('errors.file.0', 'Attach photos, videos or documents only.');
    attachToTasking(EvidenceFixtures::renamedExecutable())->assertUnprocessable();
    CrossFixtures::system();

    $attachments = TaskingAttachment::query()->get();
    expect($attachments->pluck('media_type.value')->sort()->values()->all())->toBe(['document', 'document', 'image', 'video'])
        ->and($attachments->every(fn (TaskingAttachment $a) => $a->lga_id === null && $a->state_id === $this->sd->state_id))->toBeTrue()
        ->and($attachments->every(fn (TaskingAttachment $a) => $a->original()?->disk === 'evidence'))->toBeTrue()
        ->and($attachments->every(fn (TaskingAttachment $a) => $a->sha256 === hash_file('sha256', (string) $a->original()?->getPath())))->toBeTrue();
});

test('the tasking\'s attachments are fixed once it is sent', function () {
    attachToTasking(EvidenceFixtures::photo())->assertCreated();
    CrossFixtures::system();
    $photo = TaskingAttachment::query()->sole();
    app(JurisdictionContext::class)->actingAs($this->sd, fn () => app(SendTasking::class)($this->draft, [$this->a->id], $this->sd));
    CrossFixtures::system();

    attachToTasking(EvidenceFixtures::photo())->assertForbidden();
    CrossFixtures::signIn($this->sd)->delete("/tasking-attachments/{$photo->ulid}", ['reason' => 'Wrong photo attached'])->assertForbidden();
    expect(fn () => DB::transaction(fn () => DB::table('tasking_attachments')->where('id', $photo->id)->update(['caption' => 'Changed'])))
        ->toThrow(QueryException::class, 'a sent tasking cannot be changed');

    // The recipient chief can see it.
    CrossFixtures::signIn($this->chief)->get("/tasking-attachments/{$photo->ulid}/download")->assertOk();
});

test('an infected file is refused and reported; when the scanner is down, attachments are refused', function () {
    $infected = UploadedFile::fake()->createWithContent('photo.jpg', (string) file_get_contents(EvidenceFixtures::photo()->getRealPath()).FakeMalwareScanner::eicar());
    attachToTasking($infected)->assertUnprocessable()->assertJsonPath('errors.file.0', MalwareFree::INFECTED);
    Notification::assertSentTo($this->admin, MalwareDetected::class, fn (MalwareDetected $n) => $n->kind->value === 'tasking_attachment');

    $this->malware->down();
    attachToTasking(EvidenceFixtures::photo())->assertUnprocessable()->assertJsonPath('errors.file.0', MalwareFree::UNAVAILABLE);
    CrossFixtures::system();
    expect(TaskingAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('evidence')->allFiles())->toBe([]);
});

test('large files are quarantined and scanned on the queue, like evidence', function () {
    config()->set('buffalo.malware.inline_max_mb', 0);
    $this->malware->down();
    attachToTasking(EvidenceFixtures::photo())->assertCreated()->assertJsonPath('attachment.scan.value', 'quarantined');
    CrossFixtures::system();
    $attachment = TaskingAttachment::query()->sole();
    CrossFixtures::signIn($this->sd)->get("/tasking-attachments/{$attachment->ulid}/download")->assertNotFound();

    $this->malware->available = true;
    $this->artisan('buffalo:malware:scan-quarantined')->assertSuccessful();

    expect($attachment->fresh()?->scan_status)->toBe(EvidenceScanStatus::Clean)
        ->and($attachment->fresh()?->processed_at)->not->toBeNull();
});

test('a chief attaches to their own report back only, and may remove until completing', function () {
    $tasking = TaskingFixtures::sent($this->sd, [$this->a, $this->world->lgas['A1b']]);
    CrossFixtures::system();
    $row = TaskingRecipient::query()->where('tasking_id', $tasking->id)->where('lga_id', $this->a->id)->sole();
    $post = fn (User $as, TaskingRecipient $r) => CrossFixtures::signIn($as)->postJson("/tasking-recipients/{$r->ulid}/attachments", ['file' => EvidenceFixtures::photo()]);

    $post($this->chief, $row)->assertCreated();
    $post($this->chiefB, $row)->assertNotFound();
    $post($this->sd, $row)->assertForbidden();
    CrossFixtures::system();
    $photo = TaskingAttachment::query()->sole();
    expect($photo->lga_id)->toBe($this->a->id)
        ->and($photo->tasking_recipient_id)->toBe($row->id);

    // The other LGA's chief never reaches it; the directors do.
    CrossFixtures::signIn($this->chiefB)->get("/tasking-attachments/{$photo->ulid}/thumb")->assertNotFound();
    CrossFixtures::signIn($this->sd)->get("/tasking-attachments/{$photo->ulid}/download")->assertOk();

    // Removal with a reason before completing; afterwards it is fixed.
    $post($this->chief, $row)->assertCreated();
    CrossFixtures::system();
    $second = TaskingAttachment::query()->latest('id')->firstOrFail();
    CrossFixtures::signIn($this->chief)->delete("/tasking-attachments/{$second->ulid}", ['reason' => 'Wrong photo'])->assertSessionHasNoErrors();
    CrossFixtures::system();
    expect(TaskingAttachment::query()->count())->toBe(1)
        ->and(TaskingAttachment::withTrashed()->count())->toBe(2);

    CrossFixtures::signIn($this->chief)->post("/tasking-recipients/{$row->ulid}/complete", ['response' => '<p>Done, photo attached.</p>']);
    CrossFixtures::signIn($this->chief)->delete("/tasking-attachments/{$photo->ulid}", ['reason' => 'Changed my mind'])->assertForbidden();
    // Files may still be added with further responses.
    $post($this->chief, $row)->assertCreated();
});

test('attachments are never classified below their tasking', function () {
    $draft = TaskingFixtures::draft($this->sd, ['classification' => 'confidential']);
    attachToTasking(EvidenceFixtures::photo(), tasking: $draft)->assertCreated()->assertJsonPath('attachment.classification.value', 'confidential');
    CrossFixtures::signIn($this->sd)->postJson("/taskings/{$draft->ulid}/attachments", ['file' => EvidenceFixtures::photo(), 'classification' => 'restricted'])
        ->assertUnprocessable()->assertJsonPath('errors.classification.0', 'An attachment cannot be classified below its tasking (Confidential).');
});
