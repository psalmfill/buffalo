<?php

use App\Actions\Evidence\StoreEvidence;
use App\Enums\Classification;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\RecordAccessLog;
use App\Models\User;
use App\Notifications\EvidenceIntegrityFailure;
use App\Support\Evidence\EvidenceFileInspector;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\EvidenceFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    Storage::fake('evidence');
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
});

/** Stores $file as evidence on $incident, as $user (the upload action). */
function evidenceOn(Incident $incident, UploadedFile $file, User $user, Classification $level = Classification::Restricted, string $title = 'Item'): IncidentEvidence
{
    $detected = app(EvidenceFileInspector::class)->inspect((string) $file->getRealPath(), $file->getClientOriginalName());
    expect($detected)->toBeArray();

    $evidence = app(JurisdictionContext::class)->actingAs($user, fn () => app(StoreEvidence::class)(
        $incident, $file, $detected, ['title' => $title, 'description' => null, 'classification' => $level], $user,
    ));
    CrossFixtures::system();

    return $evidence;
}

function accessLogs(IncidentEvidence $evidence, string $action, ?string $context = null): int
{
    return RecordAccessLog::query()
        ->where('subject_type', $evidence->getMorphClass())->where('subject_id', $evidence->id)
        ->where('action', $action)
        ->when($context !== null, fn ($q) => $q->where('context', $context))
        ->count();
}

test('evidence follows the jurisdiction matrix and moves with its incident', function (string $who) {
    foreach (['own_lga' => 'A1a', 'same_state' => 'A1b', 'same_zone' => 'A2', 'other_zone' => 'B1'] as $label => $lga) {
        $incident = Incident::factory()->in($this->world->lgas[$lga])->create();
        $owner = User::factory()->directorGeneral()->create();
        evidenceOn($incident, EvidenceFixtures::pdf(), $owner, title: $label);
    }

    $expected = array_values(array_filter(JurisdictionWorld::EXPECTED[$who], fn (string $l) => $l !== 'state_level'));
    sort($expected);

    expect(app(JurisdictionContext::class)->actingAs($this->world->users[$who], fn () => IncidentEvidence::query()->orderBy('title')->pluck('title')->all()))
        ->toBe($expected);
})->with(array_keys(JurisdictionWorld::EXPECTED));

test('evidence carries its own place, kept in step with the incident', function () {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $evidence = evidenceOn($incident, EvidenceFixtures::pdf(), $this->chief);
    $b1 = $this->world->lgas['B1'];

    $incident->forceFill(['lga_id' => $b1->id, 'state_id' => $b1->state_id])->save();

    $row = DB::table('incident_evidence')->where('id', $evidence->id)->first();
    expect($row->lga_id)->toBe($b1->id)->and($row->state_id)->toBe($b1->state_id);
});

test('items above the viewer clearance are absent, and every route is 404 for them', function () {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $sd = $this->world->users['state_director'];
    $secret = evidenceOn($incident, EvidenceFixtures::photo(), $sd, Classification::Secret, 'Secret photo');
    evidenceOn($incident, EvidenceFixtures::pdf(), $this->chief, title: 'Open statement');

    CrossFixtures::signIn($this->world->users['deputy_state_director'])->get("/incidents/{$incident->ulid}")
        ->assertInertia(fn (Assert $page) => $page->has('evidence.items', 1)->where('evidence.items.0.title', 'Open statement'))
        ->assertDontSee($secret->ulid);

    foreach (['', '/thumb', '/preview', '/stream', '/download', '/custody'] as $route) {
        CrossFixtures::signIn($this->world->users['deputy_state_director'])->get("/evidence/{$secret->ulid}{$route}")->assertNotFound();
    }

    CrossFixtures::signIn($sd)->get("/evidence/{$secret->ulid}/preview")->assertOk();
});

test('every route is 404 out of jurisdiction and for super admins', function (string $who) {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $photo = evidenceOn($incident, EvidenceFixtures::photo(), $this->chief);
    $user = $who === 'other_chief' ? CrossFixtures::chiefOf($this->world->lgas['A1b']) : $this->world->users[$who];

    foreach (['/thumb', '/preview', '/download', '/custody'] as $route) {
        CrossFixtures::signIn($user)->get("/evidence/{$photo->ulid}{$route}")->assertNotFound();
    }
    CrossFixtures::signIn($user)->put("/evidence/{$photo->ulid}", ['title' => 'x', 'classification' => 'restricted'])->assertNotFound();
})->with(['super_admin', 'ddg_zone_b', 'other_chief']);

test('images: thumbnail and preview inline, never cached; the preview view is logged, thumbnails are not', function () {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $photo = evidenceOn($incident, EvidenceFixtures::photo(), $this->chief);

    $thumb = CrossFixtures::signIn($this->chief)->get("/evidence/{$photo->ulid}/thumb")->assertOk();
    expect($thumb->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($thumb->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($thumb->headers->get('Cache-Control'))->toContain('no-store')
        ->and($thumb->headers->get('Cache-Control'))->toContain('private');

    $preview = CrossFixtures::signIn($this->chief)->get("/evidence/{$photo->ulid}/preview")->assertOk();
    expect($preview->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and((string) file_get_contents($preview->getFile()->getPathname()))->not->toContain(EvidenceFixtures::MARKER)
        ->and(accessLogs($photo, 'view', 'preview'))->toBe(1)
        ->and(accessLogs($photo, 'view', 'thumb'))->toBe(0);

    // Documents have no preview or thumbnail, and are never streamed.
    $pdf = evidenceOn($incident, EvidenceFixtures::pdf(), $this->chief);
    foreach (['/thumb', '/preview', '/stream'] as $route) {
        CrossFixtures::signIn($this->chief)->get("/evidence/{$pdf->ulid}{$route}")->assertNotFound();
    }
});

test('the original is downloaded unaltered as an attachment, hash checked, logged', function () {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $file = EvidenceFixtures::pdf();
    $bytes = (string) file_get_contents((string) $file->getRealPath());
    $pdf = evidenceOn($incident, $file, $this->chief);

    $response = CrossFixtures::signIn($this->chief)->get("/evidence/{$pdf->ulid}/download")->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Content-Disposition'))->toContain('evidence-'.strtolower($pdf->ulid).'.pdf')
        ->and($response->headers->get('Content-Disposition'))->not->toContain('statement')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(file_get_contents($response->getFile()->getPathname()))->toBe($bytes)
        ->and(hash('sha256', $bytes))->toBe($pdf->sha256)
        ->and(accessLogs($pdf, 'download'))->toBe(1);

    // The original photo keeps its EXIF when downloaded.
    $photo = evidenceOn($incident, EvidenceFixtures::photo(), $this->chief);
    $download = CrossFixtures::signIn($this->chief)->get("/evidence/{$photo->ulid}/download")->assertOk();
    expect(exif_read_data($download->getFile()->getPathname())['Make'] ?? null)->toBe(EvidenceFixtures::MARKER);
});

test('video streams inline with range support, logged once per session', function () {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $video = evidenceOn($incident, EvidenceFixtures::mp4(8192), $this->chief);
    $client = CrossFixtures::signIn($this->chief);

    $full = $client->get("/evidence/{$video->ulid}/stream")->assertOk();
    expect($full->headers->get('Content-Type'))->toBe('video/mp4')
        ->and($full->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($full->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($full->headers->get('Cache-Control'))->toContain('no-store');

    $partial = $client->withHeaders(['Range' => 'bytes=0-99'])->get("/evidence/{$video->ulid}/stream")->assertStatus(206);
    expect($partial->headers->get('Content-Range'))->toBe('bytes 0-99/'.$video->size_bytes)
        ->and($partial->headers->get('Content-Length'))->toBe('100');

    $client->withHeaders(['Range' => 'bytes=100-199'])->get("/evidence/{$video->ulid}/stream")->assertStatus(206);

    expect(accessLogs($video, 'view', 'stream'))->toBe(1);
    $client->flushHeaders();

    // A new session logs again.
    CrossFixtures::signIn($this->chief)->get("/evidence/{$video->ulid}/stream")->assertOk();
    expect(accessLogs($video, 'view', 'stream'))->toBe(2);
});

test('listing items on the incident page logs each one', function () {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $pdf = evidenceOn($incident, EvidenceFixtures::pdf(), $this->chief);

    CrossFixtures::signIn($this->chief)->get("/incidents/{$incident->ulid}")->assertOk();

    expect(accessLogs($pdf, 'view', 'incident_page'))->toBe(1);
});

test('a tampered file cannot be downloaded: integrity_failure is logged and the DG and super admins are told', function () {
    Notification::fake();
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $photo = evidenceOn($incident, EvidenceFixtures::photo(), $this->chief);

    file_put_contents((string) $photo->original()?->getPath(), 'altered', FILE_APPEND);

    CrossFixtures::signIn($this->chief)->get("/evidence/{$photo->ulid}/download")
        ->assertRedirect("/incidents/{$incident->ulid}")
        ->assertSessionHas('toast.type', 'error');

    $failure = Activity::query()->where('event', 'integrity_failure')->sole();
    expect($failure->subject_id)->toBe($photo->id)
        ->and($failure->properties['context'])->toBe('download')
        ->and($failure->properties['expected_sha256'])->toBe($photo->sha256)
        ->and(accessLogs($photo, 'download'))->toBe(0);

    Notification::assertSentTo($this->world->users['director_general'], EvidenceIntegrityFailure::class, fn (EvidenceIntegrityFailure $n) => $n->evidenceUlid === $photo->ulid);
    Notification::assertSentTo($this->world->users['super_admin'], EvidenceIntegrityFailure::class);
    Notification::assertNotSentTo($this->chief, EvidenceIntegrityFailure::class);

    $superAdmin = $this->world->users['super_admin'];
    expect((new EvidenceIntegrityFailure($photo->ulid))->url($superAdmin))->toBeNull()
        ->and((new EvidenceIntegrityFailure($photo->ulid))->toArray($superAdmin))->not->toHaveKey('title_of_evidence');
});

test('verify integrity: SD and above, recomputes and logs the result', function () {
    Notification::fake();
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $pdf = evidenceOn($incident, EvidenceFixtures::pdf(), $this->chief);

    CrossFixtures::signIn($this->world->users['deputy_state_director'])->post("/evidence/{$pdf->ulid}/verify")->assertForbidden();
    CrossFixtures::signIn($this->world->users['state_director'])->post("/evidence/{$pdf->ulid}/verify")->assertSessionHas('toast.type', 'success');
    expect(Activity::query()->where('event', 'integrity_verified')->where('subject_id', $pdf->id)->count())->toBe(1);

    file_put_contents((string) $pdf->original()?->getPath(), 'x', FILE_APPEND);
    CrossFixtures::signIn($this->world->users['state_director'])->post("/evidence/{$pdf->ulid}/verify")->assertSessionHas('toast.type', 'error');
    expect(Activity::query()->where('event', 'integrity_failure')->where('subject_id', $pdf->id)->count())->toBe(1);
    Notification::assertSentTo($this->world->users['director_general'], EvidenceIntegrityFailure::class);
});

test('deletion: SD and above with a reason; a soft delete; the file is kept', function () {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $pdf = evidenceOn($incident, EvidenceFixtures::pdf(), $this->chief);
    $path = (string) $pdf->original()?->getPath();

    CrossFixtures::signIn($this->world->users['deputy_state_director'])->delete("/evidence/{$pdf->ulid}", ['reason' => 'Duplicate of another upload.'])->assertForbidden();
    CrossFixtures::signIn($this->chief)->delete("/evidence/{$pdf->ulid}", ['reason' => 'Duplicate of another upload.'])->assertForbidden();
    CrossFixtures::signIn($this->world->users['state_director'])->delete("/evidence/{$pdf->ulid}", ['reason' => ''])->assertSessionHasErrors('reason');
    CrossFixtures::signIn($this->world->users['state_director'])->delete("/evidence/{$pdf->ulid}", ['reason' => 'Duplicate of another upload.'])->assertSessionHasNoErrors();

    expect(IncidentEvidence::query()->find($pdf->id))->toBeNull()
        ->and(IncidentEvidence::withTrashed()->find($pdf->id)?->deleted_at)->not->toBeNull()
        ->and(is_file($path))->toBeTrue()
        ->and(Activity::query()->where('event', 'evidence_deleted')->sole()->properties['reason'])->toBe('Duplicate of another upload.');
});

test('metadata edits: the uploader or the SD/DSD of the state, within the classification rules; changes are in custody', function () {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $photo = evidenceOn($incident, EvidenceFixtures::photo(), $this->chief);
    $payload = ['title' => 'Bus at the toll gate', 'description' => 'Taken by a passer-by', 'captured_at' => '2026-09-30T14:00', 'latitude' => 6.46, 'longitude' => 3.39, 'classification' => 'restricted'];

    CrossFixtures::signIn($this->world->users['director_general'])->put("/evidence/{$photo->ulid}", $payload)->assertForbidden();
    CrossFixtures::signIn($this->chief)->put("/evidence/{$photo->ulid}", [...$payload, 'classification' => 'secret'])->assertSessionHasErrors('classification');
    CrossFixtures::signIn($this->chief)->put("/evidence/{$photo->ulid}", $payload)->assertSessionHasNoErrors();
    CrossFixtures::signIn($this->world->users['state_director'])->put("/evidence/{$photo->ulid}", [...$payload, 'classification' => 'confidential'])->assertSessionHasNoErrors();

    $fresh = IncidentEvidence::query()->withCoordinates()->findOrFail($photo->id);
    expect($fresh->title)->toBe('Bus at the toll gate')
        ->and(round((float) $fresh->latitude, 2))->toBe(6.46)
        ->and($fresh->classification)->toBe(Classification::Confidential);

    CrossFixtures::signIn($this->world->users['state_director'])->get("/evidence/{$photo->ulid}/download")->assertOk();
    CrossFixtures::signIn($this->world->users['state_director'])->get("/evidence/{$photo->ulid}/preview")->assertOk();

    $custody = CrossFixtures::signIn($this->world->users['state_director'])->getJson("/evidence/{$photo->ulid}/custody")->assertOk()->json('entries');
    $events = array_column($custody, 'event');

    expect($events)->toContain('upload', 'metadata', 'classification', 'download', 'view')
        ->and($custody[0]['event'])->toBe('upload')
        ->and($custody[0]['detail'])->toContain($photo->sha256)
        ->and($custody[0]['who'])->toBe($this->chief->name)
        ->and(collect($custody)->firstWhere('event', 'download')['who'])->toBe($this->world->users['state_director']->name);
});

test('a notification link opens the incident', function () {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $pdf = evidenceOn($incident, EvidenceFixtures::pdf(), $this->chief);

    CrossFixtures::signIn($this->world->users['director_general'])->get("/evidence/{$pdf->ulid}")
        ->assertRedirect("/incidents/{$incident->ulid}#evidence-{$pdf->ulid}");
});

test('no media URL helpers are used anywhere in the application', function () {
    $offending = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && preg_match('/\b(getUrl|getFullUrl|getTemporaryUrl)\s*\(/', (string) file_get_contents($file->getPathname())) === 1) {
            $offending[] = $file->getPathname();
        }
    }

    expect($offending)->toBe([]);
});

test('the evidence policy by role', function (string $who, bool $view, bool $upload, bool $update, bool $download, bool $verify, bool $delete) {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->chief)->create();
    $pdf = evidenceOn($incident, EvidenceFixtures::pdf(), $this->chief);
    $user = $this->world->users[$who];

    app(JurisdictionContext::class)->actingAs($user, function () use ($user, $incident, $pdf, $view, $upload, $update, $download, $verify, $delete) {
        expect($user->can('view', $pdf))->toBe($view)
            ->and($user->can('upload', [IncidentEvidence::class, $incident]))->toBe($upload)
            ->and($user->can('update', $pdf))->toBe($update)
            ->and($user->can('download', $pdf))->toBe($download)
            ->and($user->can('verify', $pdf))->toBe($verify)
            ->and($user->can('delete', $pdf))->toBe($delete);
    });
})->with([
    'super admin' => ['super_admin', false, false, false, false, false, false],
    'director general' => ['director_general', true, false, false, true, true, true],
    'national DDG' => ['ddg_national', true, false, false, true, true, true],
    'DDG of another zone' => ['ddg_zone_b', false, false, false, false, false, false],
    'state director' => ['state_director', true, true, true, true, true, true],
    'deputy state director' => ['deputy_state_director', true, true, true, true, false, false],
    'LGA chief (uploader)' => ['lga_chief', true, true, true, true, false, false],
]);
