<?php

use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\CoverageReportStatus;
use App\Enums\EvidenceScanStatus;
use App\Models\CoverageReport;
use App\Models\CoverageReportAttachment;
use App\Models\RecordAccessLog;
use App\Models\User;
use App\Notifications\MalwareDetected;
use App\Rules\MalwareFree;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\EvidenceFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Malware\FakeMalwareScanner;

/*
| Phase 11G.2: photos and videos on security reports, stored and served like
| incident evidence (Phase 5B): content detection, malware scan, size and
| count limits, SHA-256 integrity, EXIF-free previews, streaming, locking
| with the report, access logging, and the evidence disk (backed up).
*/

beforeEach(function () {
    Notification::fake();
    Storage::fake('evidence');
    Storage::fake('quarantine');
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->admin = User::factory()->superAdmin()->create();
    $this->report = CoverageReport::factory()->in($this->world->lgas['A1a'])->create([
        'created_by' => $this->chief->id, 'summary' => '<p>Report.</p>', 'period_type' => 'ad_hoc', 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
    ]);
});

function attach(UploadedFile $file, array $values = [], ?User $as = null, ?CoverageReport $report = null): TestResponse
{
    $report ??= test()->report;

    return CrossFixtures::signIn($as ?? test()->chief)->postJson("/security-reports/{$report->ulid}/attachments", ['file' => $file, 'caption' => 'At the market', ...$values]);
}

function attachmentLogs(CoverageReportAttachment $attachment, AccessAction $action, ?string $context = null): int
{
    return RecordAccessLog::query()->where('subject_type', $attachment->getMorphClass())->where('subject_id', $attachment->id)
        ->where('action', $action->value)->when($context, fn ($q, $c) => $q->where('context', $c))->count();
}

test('photos and videos are accepted by their content; everything else is refused', function () {
    attach(EvidenceFixtures::photo())->assertCreated()->assertJsonPath('attachment.type.value', 'image')->assertJsonPath('attachment.caption', 'At the market');
    attach(EvidenceFixtures::png())->assertCreated();
    attach(EvidenceFixtures::mp4())->assertCreated()->assertJsonPath('attachment.type.value', 'video');
    attach(EvidenceFixtures::webm())->assertCreated();

    foreach ([EvidenceFixtures::pdf(), EvidenceFixtures::docx(), EvidenceFixtures::mp3(), EvidenceFixtures::renamedExecutable(), EvidenceFixtures::png('scene.jpg')] as $file) {
        attach($file)->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    expect(CoverageReportAttachment::query()->count())->toBe(4);
});

test('size limits are those of evidence: images 25 MB, videos 100 MB', function () {
    config()->set('buffalo.evidence.max_mb.video', 0);
    attach(EvidenceFixtures::mp4())->assertUnprocessable()->assertJsonPath('errors.file.0', 'Video files may be at most 0 MB.');
    config()->set('buffalo.evidence.max_mb.image', 0);
    attach(EvidenceFixtures::photo())->assertUnprocessable()->assertJsonPath('errors.file.0', 'Image files may be at most 0 MB.');
});

test('at most 30 attachments per report', function () {
    config()->set('buffalo.security_reports.max_attachments', 2);
    attach(EvidenceFixtures::photo())->assertCreated();
    attach(EvidenceFixtures::photo())->assertCreated();
    attach(EvidenceFixtures::photo())->assertUnprocessable()->assertJsonPath('errors.file.0', 'A security report can carry at most 2 photos and videos.');
});

test('an infected file is refused before anything is stored, and reported', function () {
    $infected = UploadedFile::fake()->createWithContent('photo.jpg', (string) file_get_contents(EvidenceFixtures::photo()->getRealPath()).FakeMalwareScanner::eicar());

    attach($infected)->assertUnprocessable()->assertJsonPath('errors.file.0', MalwareFree::INFECTED);

    expect(CoverageReportAttachment::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk('evidence')->allFiles())->toBe([]);
    Notification::assertSentTo($this->admin, MalwareDetected::class, fn (MalwareDetected $n) => $n->kind->value === 'report_attachment');
});

test('when the scanner is down, attachments are refused (fail closed)', function () {
    $this->malware->down();

    attach(EvidenceFixtures::photo())->assertUnprocessable()->assertJsonPath('errors.file.0', MalwareFree::UNAVAILABLE);
    expect(CoverageReportAttachment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('large files are quarantined, never served, and scanned on the queue', function () {
    config()->set('buffalo.malware.inline_max_mb', 0);
    $this->malware->down();

    attach(EvidenceFixtures::photo())->assertCreated()->assertJsonPath('attachment.scan.value', 'quarantined')->assertJsonPath('attachment.can.download', false);
    $attachment = CoverageReportAttachment::query()->latest('id')->firstOrFail();
    CrossFixtures::signIn($this->chief)->get("/security-report-attachments/{$attachment->ulid}/download")->assertNotFound();

    $this->malware->available = true;
    $this->artisan('buffalo:malware:scan-quarantined')->assertSuccessful();

    expect($attachment->fresh()->scan_status)->toBe(EvidenceScanStatus::Clean)
        ->and($attachment->fresh()->processed_at)->not->toBeNull()
        ->and($attachment->fresh()->captured_at)->not->toBeNull(); // EXIF read once clean
});

test('originals are kept unaltered on the evidence disk and verified on download', function () {
    attach(EvidenceFixtures::photo())->assertCreated();
    $attachment = CoverageReportAttachment::query()->latest('id')->firstOrFail();
    $media = $attachment->original();

    // The evidence disk (backed up with evidence, Phase 8C), under its own collection.
    expect($media?->disk)->toBe('evidence')
        ->and($media?->getPathRelativeToRoot())->toStartWith('report_attachment/')
        ->and(hash_file('sha256', (string) $media?->getPath()))->toBe($attachment->sha256);

    $download = CrossFixtures::signIn($this->chief)->get("/security-report-attachments/{$attachment->ulid}/download")->assertOk();
    expect($download->headers->get('Content-Disposition'))->toContain('report-attachment-'.strtolower($attachment->ulid).'.jpg')
        ->and(exif_read_data($download->getFile()->getPathname())['Make'] ?? null)->toBe(EvidenceFixtures::MARKER)
        ->and(attachmentLogs($attachment, AccessAction::Download))->toBe(1);

    // Tampered: refused, logged, and the DG notified.
    file_put_contents((string) $media?->getPath(), 'altered');
    CrossFixtures::signIn($this->chief)->get("/security-report-attachments/{$attachment->ulid}/download")->assertRedirect();
    expect(Activity::query()->where('event', 'integrity_failure')->where('subject_id', $attachment->id)->exists())->toBeTrue();
});

test('previews carry no EXIF; viewing them is logged; the report page logs each attachment', function () {
    attach(EvidenceFixtures::photo())->assertCreated();
    $attachment = CoverageReportAttachment::query()->latest('id')->firstOrFail();

    $preview = CrossFixtures::signIn($this->sd)->get("/security-report-attachments/{$attachment->ulid}/preview")->assertOk();
    expect($preview->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and((string) file_get_contents($preview->getFile()->getPathname()))->not->toContain(EvidenceFixtures::MARKER)
        ->and($preview->headers->get('Cache-Control'))->toContain('no-store')
        ->and(attachmentLogs($attachment, AccessAction::View, 'preview'))->toBe(1);

    CrossFixtures::signIn($this->sd)->get("/security-reports/{$this->report->ulid}")->assertInertia(fn (Assert $page) => $page
        ->has('attachments', 1)
        ->where('attachments.0.has_preview', true)
        ->missing('attachments.0.id'));
    expect(attachmentLogs($attachment, AccessAction::View, 'report_page'))->toBe(1);
});

test('videos stream with range support, logged once per session', function () {
    attach(EvidenceFixtures::mp4(8192))->assertCreated();
    $video = CoverageReportAttachment::query()->latest('id')->firstOrFail();
    $client = CrossFixtures::signIn($this->chief);

    $client->get("/security-report-attachments/{$video->ulid}/stream")->assertOk();
    $partial = $client->withHeaders(['Range' => 'bytes=0-99'])->get("/security-report-attachments/{$video->ulid}/stream")->assertStatus(206);

    expect($partial->headers->get('Content-Range'))->toBe('bytes 0-99/'.$video->size_bytes)
        ->and(attachmentLogs($video, AccessAction::View, 'stream'))->toBe(1);
    // Photos have no stream.
    attach(EvidenceFixtures::photo())->assertCreated();
    $client->get('/security-report-attachments/'.CoverageReportAttachment::query()->latest('id')->firstOrFail()->ulid.'/stream')->assertNotFound();
});

test('attachments are never below the report, and are raised with it', function () {
    attach(EvidenceFixtures::photo(), ['classification' => 'secret'], $this->sd)->assertCreated()->assertJsonPath('attachment.classification.value', 'secret');
    attach(EvidenceFixtures::photo())->assertCreated()->assertJsonPath('attachment.classification.value', 'restricted');
    $restricted = CoverageReportAttachment::query()->latest('id')->firstOrFail();

    $confidential = CoverageReport::factory()->in($this->world->lgas['A1a'])->classified(Classification::Confidential)->create(['created_by' => $this->sd->id]);
    attach(EvidenceFixtures::photo(), ['classification' => 'restricted'], $this->sd, $confidential)->assertUnprocessable()->assertJsonValidationErrors('classification');
    attach(EvidenceFixtures::photo(), [], $this->sd, $confidential)->assertCreated()->assertJsonPath('attachment.classification.value', 'confidential');

    // Raising the report raises its attachments first.
    CrossFixtures::signIn($this->sd)->put("/security-reports/{$this->report->ulid}", [
        'summary' => '<p>Report.</p>', 'classification' => 'confidential', 'deployments' => [],
    ])->assertSessionHasNoErrors();

    expect($restricted->fresh()->classification)->toBe(Classification::Confidential);
});

test('attachments lock with the report once reviewed; removal needs a reason and keeps the file', function () {
    attach(EvidenceFixtures::photo())->assertCreated();
    $attachment = CoverageReportAttachment::query()->latest('id')->firstOrFail();

    CrossFixtures::signIn($this->chief)->delete("/security-report-attachments/{$attachment->ulid}", ['reason' => ''])->assertSessionHasErrors('reason');
    CrossFixtures::signIn($this->chief)->put("/security-report-attachments/{$attachment->ulid}", ['caption' => 'Burnt stalls', 'classification' => 'restricted'])->assertSessionHasNoErrors();
    expect($attachment->fresh()->caption)->toBe('Burnt stalls');

    $this->report->forceFill(['status' => CoverageReportStatus::Submitted, 'submitted_at' => now()])->save();
    $this->report->forceFill(['status' => CoverageReportStatus::Reviewed, 'reviewed_at' => now()])->save();

    attach(EvidenceFixtures::photo())->assertForbidden();
    CrossFixtures::signIn($this->chief)->put("/security-report-attachments/{$attachment->ulid}", ['caption' => 'Changed', 'classification' => 'restricted'])->assertForbidden();
    CrossFixtures::signIn($this->chief)->delete("/security-report-attachments/{$attachment->ulid}", ['reason' => 'No longer needed'])->assertForbidden();
    // And in the database itself.
    expect(fn () => DB::transaction(fn () => $attachment->fresh()->forceFill(['caption' => 'Changed'])->save()))->toThrow(QueryException::class);

    // A removable one: a fresh draft.
    $draft = CoverageReport::factory()->in($this->world->lgas['A1a'])->create(['created_by' => $this->chief->id, 'period_type' => 'ad_hoc', 'period_start' => now()->subDay()->toDateString(), 'period_end' => now()->subDay()->toDateString()]);
    attach(EvidenceFixtures::photo(), [], null, $draft)->assertCreated();
    $removable = CoverageReportAttachment::query()->latest('id')->firstOrFail();
    $path = (string) $removable->original()?->getPath();
    CrossFixtures::signIn($this->chief)->delete("/security-report-attachments/{$removable->ulid}", ['reason' => 'Wrong photo'])->assertSessionHasNoErrors();

    expect(CoverageReportAttachment::query()->whereKey($removable->id)->exists())->toBeFalse()
        ->and(CoverageReportAttachment::withTrashed()->whereKey($removable->id)->exists())->toBeTrue()
        ->and(is_file($path))->toBeTrue()
        ->and(Activity::query()->where('event', 'attachment_deleted')->where('subject_id', $removable->id)->first()?->properties['reason'])->toBe('Wrong photo');
});

test('only those who may edit the report add attachments', function () {
    $dsd = $this->world->users['deputy_state_director'];
    attach(EvidenceFixtures::photo(), [], $dsd)->assertCreated(); // the state's Deputy may edit
    attach(EvidenceFixtures::photo(), [], $this->world->users['director_general'])->assertForbidden();
    attach(EvidenceFixtures::photo(), [], $this->admin)->assertNotFound();
});
