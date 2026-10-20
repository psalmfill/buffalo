<?php

use App\Enums\Classification;
use App\Models\Media;
use App\Models\Organization;
use App\Models\OrganizationSite;
use App\Models\OrganizationSitePhoto;
use App\Models\RecordAccessLog;
use App\Rules\MalwareFree;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Organizations\SitePhotos;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Malware\FakeMalwareScanner;
use Tests\Support\Persons\PersonFixtures;

/*
| Phase 11D.2: site photos. A photo is visible exactly when its site is,
| has the site's classification, and follows the site's place.
*/

beforeEach(function () {
    Notification::fake();
    Storage::fake('evidence');
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->organization = Organization::factory()->create(['name' => 'Gulf of Guinea Petroleum Ltd']);
    $this->site = OrganizationSite::factory()->in($this->world->lgas['A1a'])->of($this->organization)->create(['name' => 'Kano Inland Fuel Terminal']);
});

function postSitePhoto(object $test, UploadedFile $file, array $extra = [], ?OrganizationSite $site = null): TestResponse
{
    $site ??= $test->site;

    return CrossFixtures::signIn($test->chief)->from("/organization-sites/{$site->ulid}")
        ->post("/organization-sites/{$site->ulid}/photos", ['photo' => $file, ...$extra]);
}

/** @return Collection<int, OrganizationSitePhoto> */
function sitePhotos(OrganizationSite $site, bool $withTrashed = false)
{
    CrossFixtures::system();

    return OrganizationSitePhoto::query()->when($withTrashed, fn ($q) => $q->withTrashed())
        ->where('organization_site_id', $site->id)->galleryOrder()->get();
}

function photoUrl(OrganizationSite $site, OrganizationSitePhoto $photo, string $variant = 'thumb'): string
{
    return "/organization-sites/{$site->ulid}/photos/{$photo->ulid}/{$variant}";
}

test('a photo is stored without EXIF or GPS data, scaled to 2,400 px, with both conversions', function () {
    postSitePhoto($this, PersonFixtures::jpegWithExif('BUFFALOGPSMARKER', 3000, 1500), ['caption' => 'Main gate', 'taken_on' => '2026-10-01'])
        ->assertSessionHasNoErrors();

    $photo = sitePhotos($this->site)->sole();
    /** @var Media $media */
    $media = $photo->file;

    expect($photo->caption)->toBe('Main gate')
        ->and($photo->taken_on?->toDateString())->toBe('2026-10-01')
        ->and($photo->state_id)->toBe($this->site->state_id)
        ->and($photo->lga_id)->toBe($this->site->lga_id)
        ->and($photo->uploaded_by)->toBe($this->chief->id)
        ->and($media->disk)->toBe('evidence')
        ->and($media->file_name)->toBe('photo.jpg')
        ->and($media->getPathRelativeToRoot())->toBe('photo/'.strtolower($media->ulid).'/photo.jpg');

    $paths = ['' => 2400, 'thumb' => 300, 'preview' => 1200];
    foreach ($paths as $conversion => $longSide) {
        $path = Storage::disk('evidence')->path($media->getPathRelativeToRoot($conversion));
        $exif = @exif_read_data($path);

        expect($conversion === '' || $media->hasGeneratedConversion($conversion))->toBeTrue()
            ->and(max(array_slice((array) getimagesize($path), 0, 2)))->toBe($longSide)
            ->and((string) file_get_contents($path))->not->toContain('BUFFALOGPSMARKER')
            ->and((string) file_get_contents($path))->not->toContain('Adeyemi')
            ->and(is_array($exif) ? array_intersect_key($exif, array_flip(['Make', 'GPSLatitudeRef', 'GPS_IFD_Pointer'])) : [])->toBe([]);
    }

    $event = Activity::query()->where('event', 'site_photo_added')->sole();
    expect($event->subject_id)->toBe($this->site->id)
        ->and($event->properties['photo'])->toBe($photo->ulid);
});

test('photos are validated by content and size; SVG and other types are refused', function (Closure $file, bool $ok) {
    $response = postSitePhoto($this, $file());

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('photo');
    expect(sitePhotos($this->site))->toHaveCount($ok ? 1 : 0);
})->with([
    'jpeg' => [fn () => PersonFixtures::jpegWithExif(), true],
    'png' => [fn () => PersonFixtures::png(), true],
    'webp' => [fn () => UploadedFile::fake()->image('photo.webp'), true],
    'svg' => [fn () => UploadedFile::fake()->createWithContent('gate.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), false],
    'php named .jpg' => [fn () => PersonFixtures::fakeJpeg(), false],
    'gif' => [fn () => UploadedFile::fake()->image('photo.gif'), false],
    'over 15 MB' => [fn () => UploadedFile::fake()->image('photo.jpg')->size(15361), false],
]);

test('captions and dates are validated', function () {
    postSitePhoto($this, PersonFixtures::png(), ['caption' => str_repeat('a', 201)])->assertSessionHasErrors('caption');
    postSitePhoto($this, PersonFixtures::png(), ['taken_on' => now()->addDay()->toDateString()])->assertSessionHasErrors('taken_on');

    expect(sitePhotos($this->site))->toHaveCount(0);
});

test('an EICAR photo is rejected and reported before anything is stored', function () {
    postSitePhoto($this, UploadedFile::fake()->createWithContent('gate.jpg', FakeMalwareScanner::eicar()))
        ->assertSessionHasErrors(['photo' => MalwareFree::INFECTED]);

    expect(sitePhotos($this->site))->toHaveCount(0);
    $event = Activity::query()->where('event', 'malware_detected')->sole();
    expect($event->properties['kind'])->toBe('site_photo')
        ->and($event->properties['outcome'])->toBe('rejected');
});

test('photo visibility follows the site in every role and classification', function (Classification $level) {
    $site = OrganizationSite::factory()->in($this->world->lgas['A1a'])->of($this->organization)->classified($level)->create();
    // The DG uploads: every level is within their clearance.
    $dg = $this->world->users['director_general'];
    CrossFixtures::signIn($dg)->post("/organization-sites/{$site->ulid}/photos", ['photo' => PersonFixtures::png()])->assertSessionHasNoErrors();
    $photo = sitePhotos($site)->sole();

    $roles = ['super_admin', 'director_general', 'ddg_national', 'ddg_zone_a', 'ddg_zone_b', 'ddg_empty_zone', 'state_director', 'deputy_state_director', 'lga_chief'];
    $users = [...array_map(fn (string $r) => [$r, $this->world->users[$r]], $roles),
        ['chief of another LGA', CrossFixtures::chiefOf($this->world->lgas['A1b'])],
        ['director of another state', CrossFixtures::directorOfB1($this->world)],
    ];
    $seen = [];

    foreach ($users as [$label, $user]) {
        $sitePage = CrossFixtures::signIn($user)->get("/organization-sites/{$site->ulid}")->baseResponse->getStatusCode();

        foreach (['thumb', 'preview', 'original'] as $variant) {
            $status = CrossFixtures::signIn($user)->get(photoUrl($site, $photo, $variant))->baseResponse->getStatusCode();

            if ($sitePage === 200) {
                expect($status)->toBe(200, "{$label} sees the site but not its {$variant}");
            } else {
                expect($status)->toBeIn([403, 404], "{$label} cannot see the site but got its {$variant} ({$status})");
            }
        }
        $seen[$label] = $sitePage === 200;
    }

    // Sanity: the fixture really splits by jurisdiction and clearance.
    expect($seen['director_general'])->toBeTrue()
        ->and($seen['super_admin'])->toBeFalse()
        ->and($seen['ddg_zone_b'])->toBeFalse()
        ->and($seen['director of another state'])->toBeFalse()
        ->and($seen['chief of another LGA'])->toBeFalse()
        ->and($seen['lga_chief'])->toBe($level === Classification::Restricted)
        ->and($seen['deputy_state_director'])->toBe($level !== Classification::Secret);
})->with([
    'restricted' => [Classification::Restricted],
    'confidential' => [Classification::Confidential],
    'secret' => [Classification::Secret],
]);

test('another state gets 404 for the photos, and a photo of another site is 404 under this site', function () {
    postSitePhoto($this, PersonFixtures::png())->assertSessionHasNoErrors();
    $photo = sitePhotos($this->site)->sole();
    $other = OrganizationSite::factory()->in($this->world->lgas['A1a'])->of($this->organization)->create();

    CrossFixtures::signIn(CrossFixtures::directorOfB1($this->world))->get(photoUrl($this->site, $photo))->assertNotFound();
    CrossFixtures::signIn($this->chief)->get(photoUrl($other, $photo))->assertNotFound();

    // Out of reach, a photo is not visible through queries either.
    $outsider = CrossFixtures::directorOfB1($this->world);
    expect(app(JurisdictionContext::class)->actingAs($outsider, fn () => OrganizationSitePhoto::query()->count()))->toBe(0);
});

test('a photo follows its site when the site changes LGA', function () {
    postSitePhoto($this, PersonFixtures::png())->assertSessionHasNoErrors();
    $target = $this->world->lgas['B1'];

    CrossFixtures::system();
    DB::table('organization_sites')->where('id', $this->site->id)->update(['lga_id' => $target->id, 'state_id' => $target->state_id]);

    $photo = sitePhotos($this->site)->sole();
    expect($photo->lga_id)->toBe($target->id)
        ->and($photo->state_id)->toBe($target->state_id);

    // The photo can never be placed apart from its site.
    expect(fn () => DB::transaction(fn () => DB::table('organization_site_photos')->where('id', $photo->id)->update(['lga_id' => $this->world->lgas['A1a']->id, 'state_id' => $this->world->lgas['A1a']->state_id])))
        ->toThrow(QueryException::class);
});

test('the first photo is the cover; setting another keeps exactly one cover', function () {
    postSitePhoto($this, PersonFixtures::png())->assertSessionHasNoErrors();
    postSitePhoto($this, PersonFixtures::jpegWithExif())->assertSessionHasNoErrors();
    [$first, $second] = sitePhotos($this->site)->all();

    expect($first->is_cover)->toBeTrue()->and($second->is_cover)->toBeFalse();

    CrossFixtures::signIn($this->chief)->put("/organization-sites/{$this->site->ulid}/photos/{$second->ulid}/cover")->assertRedirect();

    $photos = sitePhotos($this->site);
    expect($photos->where('is_cover', true)->pluck('ulid')->all())->toBe([$second->ulid])
        ->and(Activity::query()->where('event', 'site_photo_cover_set')->sole()->properties['photo'])->toBe($second->ulid);

    // The database allows one cover per site.
    expect(fn () => DB::transaction(fn () => DB::table('organization_site_photos')->where('id', $first->id)->update(['is_cover' => true])))->toThrow(QueryException::class);

    // The cover shows on site rows.
    CrossFixtures::signIn($this->chief)->get("/organizations/{$this->organization->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('sites.0.cover', photoUrl($this->site, $second)));
});

test('a site may have at most the configured number of photos', function () {
    config(['buffalo.organizations.site_photo_limit' => 2]);

    postSitePhoto($this, PersonFixtures::png())->assertSessionHasNoErrors();
    postSitePhoto($this, PersonFixtures::png())->assertSessionHasNoErrors();
    postSitePhoto($this, PersonFixtures::png())->assertSessionHasErrors(['photo' => 'A site may have at most 2 photos. Delete one before adding another.']);

    expect(sitePhotos($this->site))->toHaveCount(2)
        ->and(config('buffalo.organizations.site_photo_limit'))->toBe(2);
});

test('the default limit is 30 photos per site', function () {
    expect(SitePhotos::limit())->toBe(30);
});

test('photos can be reordered; the order must name every live photo once', function () {
    foreach (range(1, 3) as $i) {
        postSitePhoto($this, PersonFixtures::png(), ['caption' => "Photo {$i}"])->assertSessionHasNoErrors();
    }
    [$one, $two, $three] = sitePhotos($this->site)->all();

    CrossFixtures::signIn($this->chief)->put("/organization-sites/{$this->site->ulid}/photos/order", ['order' => [$one->ulid, $three->ulid, $two->ulid]])
        ->assertSessionHasNoErrors();
    expect(sitePhotos($this->site)->pluck('caption')->all())->toBe(['Photo 1', 'Photo 3', 'Photo 2'])
        ->and(Activity::query()->where('event', 'site_photos_reordered')->sole()->properties['order'])->toBe([$one->ulid, $three->ulid, $two->ulid]);

    CrossFixtures::signIn($this->chief)->put("/organization-sites/{$this->site->ulid}/photos/order", ['order' => [$one->ulid, $two->ulid]])
        ->assertSessionHasErrors('order');
    CrossFixtures::signIn($this->chief)->put("/organization-sites/{$this->site->ulid}/photos/order", ['order' => [$one->ulid, $two->ulid, $two->ulid]])
        ->assertSessionHasErrors('order');
});

test('captions and dates can be edited, as a named event', function () {
    postSitePhoto($this, PersonFixtures::png(), ['caption' => 'Gate'])->assertSessionHasNoErrors();
    $photo = sitePhotos($this->site)->sole();

    CrossFixtures::signIn($this->chief)->put("/organization-sites/{$this->site->ulid}/photos/{$photo->ulid}", ['caption' => 'North gate and guard post', 'taken_on' => '2026-09-30'])
        ->assertSessionHasNoErrors();

    $photo = sitePhotos($this->site)->sole();
    expect($photo->caption)->toBe('North gate and guard post')
        ->and($photo->taken_on?->toDateString())->toBe('2026-09-30');
    $event = Activity::query()->where('event', 'site_photo_updated')->sole();
    expect($event->properties['old'])->toBe(['caption' => 'Gate', 'taken_on' => null]);
});

test('deleting a photo needs a reason, soft-deletes it, and passes the cover on', function () {
    postSitePhoto($this, PersonFixtures::png())->assertSessionHasNoErrors();
    postSitePhoto($this, PersonFixtures::png())->assertSessionHasNoErrors();
    [$cover, $next] = sitePhotos($this->site)->all();
    $url = "/organization-sites/{$this->site->ulid}/photos/{$cover->ulid}";

    CrossFixtures::signIn($this->chief)->delete($url)->assertSessionHasErrors('reason');
    expect(sitePhotos($this->site))->toHaveCount(2);

    CrossFixtures::signIn($this->chief)->delete($url, ['reason' => 'Shows the old fence; replaced.'])->assertSessionHasNoErrors();

    expect(sitePhotos($this->site)->pluck('ulid')->all())->toBe([$next->ulid])
        ->and(sitePhotos($this->site)->first()?->is_cover)->toBeTrue()
        ->and(sitePhotos($this->site, withTrashed: true)->firstWhere('ulid', $cover->ulid)?->trashed())->toBeTrue();
    $event = Activity::query()->where('event', 'site_photo_deleted')->sole();
    expect($event->properties['reason'])->toBe('Shows the old fence; replaced.')
        ->and($event->properties['new_cover'])->toBe($next->ulid);

    CrossFixtures::signIn($this->chief)->get(photoUrl($this->site, $cover))->assertNotFound();
});

test('managing photos needs update on the site', function () {
    postSitePhoto($this, PersonFixtures::png())->assertSessionHasNoErrors();
    $photo = sitePhotos($this->site)->sole();
    $outsider = CrossFixtures::chiefOf($this->world->lgas['A1b']);
    $base = "/organization-sites/{$this->site->ulid}/photos";

    CrossFixtures::signIn($outsider)->post($base, ['photo' => PersonFixtures::png()])->assertNotFound();
    CrossFixtures::signIn($outsider)->put("{$base}/{$photo->ulid}/cover")->assertNotFound();
    CrossFixtures::signIn($outsider)->delete("{$base}/{$photo->ulid}", ['reason' => 'Not mine to delete'])->assertNotFound();
    CrossFixtures::signIn($this->world->users['super_admin'])->post($base, ['photo' => PersonFixtures::png()])->assertNotFound();

    expect(sitePhotos($this->site))->toHaveCount(1);
});

test('photo views of classified sites are access-logged and never cached', function () {
    $site = OrganizationSite::factory()->in($this->world->lgas['A1a'])->of($this->organization)->classified(Classification::Confidential)->create();
    $sd = $this->world->users['state_director'];
    CrossFixtures::signIn($sd)->post("/organization-sites/{$site->ulid}/photos", ['photo' => PersonFixtures::png()])->assertSessionHasNoErrors();
    $photo = sitePhotos($site)->sole();
    // record_access_logs is append-only: count from here.
    $from = (int) RecordAccessLog::query()->max('id');

    $thumb = CrossFixtures::signIn($sd)->get(photoUrl($site, $photo));
    $thumb->assertOk();
    expect($thumb->headers->get('Cache-Control'))->toContain('no-store');
    CrossFixtures::signIn($sd)->get(photoUrl($site, $photo, 'original'))->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="site-photo.png"');

    $logs = RecordAccessLog::query()->where('id', '>', $from)->orderBy('id')->get();
    expect($logs->map(fn (RecordAccessLog $l) => [$l->subject_id, $l->action->value, $l->context])->all())->toBe([
        [$site->id, 'view', "photo:thumb:{$photo->ulid}"],
        [$site->id, 'download', "photo:original:{$photo->ulid}"],
    ])->and($logs->pluck('user_id')->unique()->all())->toBe([$sd->id]);

    // A restricted site's photos are not logged, and may be cached privately.
    postSitePhoto($this, PersonFixtures::png())->assertSessionHasNoErrors();
    $plain = sitePhotos($this->site)->sole();
    $from = (int) RecordAccessLog::query()->max('id');
    $response = CrossFixtures::signIn($this->chief)->get(photoUrl($this->site, $plain));
    expect((string) $response->headers->get('Cache-Control'))->toContain('private')
        ->and((string) $response->headers->get('Cache-Control'))->not->toContain('no-store')
        ->and(RecordAccessLog::query()->where('id', '>', $from)->count())->toBe(0);
});

test('the site page lists photos cover first; cover thumbnails reach cards, popups and search', function () {
    postSitePhoto($this, PersonFixtures::png(), ['caption' => 'Tank farm'])->assertSessionHasNoErrors();
    postSitePhoto($this, PersonFixtures::png(), ['caption' => 'Main gate'])->assertSessionHasNoErrors();
    [, $gate] = sitePhotos($this->site)->all();
    CrossFixtures::signIn($this->chief)->put("/organization-sites/{$this->site->ulid}/photos/{$gate->ulid}/cover");
    $cover = photoUrl($this->site, $gate);

    CrossFixtures::signIn($this->chief)->get("/organization-sites/{$this->site->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('photos.0.caption', 'Main gate')
        ->where('photos.0.is_cover', true)
        ->where('photos.0.urls.preview', photoUrl($this->site, $gate, 'preview'))
        ->where('photos.1.caption', 'Tank farm')
        ->where('photoLimit', 30)
        ->where('site.cover', $cover));

    CrossFixtures::signIn($this->chief)->get("/lgas/{$this->world->lgas['A1a']->ulid}?tab=organizations")->assertInertia(fn (Assert $page) => $page
        ->where('organizations.sites.0.cover', $cover));

    CrossFixtures::system();
    DB::table('organization_sites')->where('id', $this->site->id)->update(['location' => DB::raw('ST_SetSRID(ST_MakePoint(7.49, 9.05), 4326)::geography')]);
    $layer = CrossFixtures::signIn($this->chief)->getJson('/map/layers/sites?bbox=7,8.5,8,9.5&zoom=10&relevance=all')->assertOk()->json('features');
    expect(collect($layer)->firstWhere('properties.ulid', $this->site->ulid)['properties']['image'] ?? null)->toBe($cover);

    $hits = CrossFixtures::signIn($this->chief)->getJson('/search/quick?q=Kano+Inland+Fuel')->assertOk()->json('sections');
    expect(collect(collect($hits)->firstWhere('key', 'organizations')['hits'])->firstWhere('title', 'Kano Inland Fuel Terminal')['image'])->toBe($cover);
});
