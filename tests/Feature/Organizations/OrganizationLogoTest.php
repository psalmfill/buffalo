<?php

use App\Models\Media;
use App\Models\Organization;
use App\Models\OrganizationSite;
use App\Rules\MalwareFree;
use Illuminate\Http\UploadedFile;
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
| Phase 11D.1: organization logos. A logo belongs to the national directory
| entry: every intelligence role sees it, in any state; super admins do not.
*/

beforeEach(function () {
    Notification::fake();
    Storage::fake('evidence');
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->organization = Organization::factory()->create(['name' => 'Gulf of Guinea Petroleum Ltd']);
});

function uploadLogo(object $test, UploadedFile $file, ?Organization $organization = null): TestResponse
{
    $organization ??= $test->organization;

    return CrossFixtures::signIn($test->chief)->from("/organizations/{$organization->ulid}")
        ->post("/organizations/{$organization->ulid}/logo", ['logo' => $file]);
}

function currentLogo(Organization $organization): ?Media
{
    CrossFixtures::system();

    /** @var Media|null */
    return $organization->fresh()?->logo()->first();
}

test('a logo is stored re-encoded without metadata, at most 512 px, with a 96 px thumbnail', function () {
    uploadLogo($this, PersonFixtures::jpegWithExif('BUFFALOGPSMARKER', 900, 600))->assertSessionHasNoErrors();

    $media = currentLogo($this->organization);
    expect($media)->not->toBeNull();
    $stored = Storage::disk('evidence')->path($media->getPathRelativeToRoot());
    $thumb = Storage::disk('evidence')->path($media->getPathRelativeToRoot('thumb'));

    expect($media->disk)->toBe('evidence')
        ->and($media->file_name)->toBe('logo.jpg')
        ->and($media->getPathRelativeToRoot())->toBe('logo/'.strtolower($media->ulid).'/logo.jpg')
        ->and($media->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and(getimagesize($stored))->toMatchArray([0 => 512, 1 => 341])
        ->and(max(array_slice((array) getimagesize($thumb), 0, 2)))->toBe(96);

    foreach ([$stored, $thumb] as $path) {
        $exif = @exif_read_data($path);
        expect((string) file_get_contents($path))->not->toContain('BUFFALOGPSMARKER')
            ->and(is_array($exif) ? array_intersect_key($exif, array_flip(['Make', 'GPSLatitudeRef', 'GPS_IFD_Pointer'])) : [])->toBe([]);
    }

    $event = Activity::query()->where('event', 'logo_added')->sole();
    expect($event->properties['logo'])->toBe($media->ulid)
        ->and($event->subject_id)->toBe($this->organization->id);
});

test('logos are validated by content and size, and SVG is never accepted', function (Closure $file, bool $ok) {
    $response = uploadLogo($this, $file());

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('logo');
    expect(currentLogo($this->organization) !== null)->toBe($ok);
})->with([
    'jpeg' => [fn () => PersonFixtures::jpegWithExif(), true],
    'png' => [fn () => PersonFixtures::png(), true],
    'webp' => [fn () => UploadedFile::fake()->image('logo.webp'), true],
    'svg' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), false],
    'svg named .png' => [fn () => UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), false],
    'php named .jpg' => [fn () => PersonFixtures::fakeJpeg(), false],
    'gif' => [fn () => UploadedFile::fake()->image('logo.gif'), false],
    'over 2 MB' => [fn () => UploadedFile::fake()->image('logo.png')->size(2049), false],
]);

test('an EICAR logo is rejected and reported before anything is stored', function () {
    uploadLogo($this, UploadedFile::fake()->createWithContent('logo.png', FakeMalwareScanner::eicar()))
        ->assertSessionHasErrors(['logo' => MalwareFree::INFECTED]);

    expect(currentLogo($this->organization))->toBeNull();
    $event = Activity::query()->where('event', 'malware_detected')->sole();
    expect($event->properties['kind'])->toBe('organization_logo')
        ->and($event->properties['outcome'])->toBe('rejected');
});

test('every intelligence role sees the logo in any state; super admins do not', function () {
    uploadLogo($this, PersonFixtures::png())->assertSessionHasNoErrors();
    $media = currentLogo($this->organization);
    $url = "/organizations/{$this->organization->ulid}/logo/{$media->ulid}";

    $viewers = ['director_general', 'ddg_national', 'ddg_zone_a', 'ddg_zone_b', 'ddg_empty_zone', 'state_director', 'deputy_state_director', 'lga_chief'];
    foreach ($viewers as $role) {
        foreach (['', '/thumb'] as $suffix) {
            $response = CrossFixtures::signIn($this->world->users[$role])->get($url.$suffix);
            $response->assertOk();
            expect($response->headers->get('Content-Type'))->toBe('image/png')
                ->and((string) $response->headers->get('Cache-Control'))->toContain('private')
                ->and((string) $response->headers->get('Cache-Control'))->toContain('max-age=31536000');
        }
    }
    // A chief of a state in another zone, too.
    CrossFixtures::signIn(CrossFixtures::chiefOf($this->world->lgas['B1']))->get($url)->assertOk();

    CrossFixtures::signIn($this->world->users['super_admin'])->get($url)->assertForbidden();
    // Another organization's URL with this media is 404.
    $other = Organization::factory()->create();
    CrossFixtures::signIn($this->chief)->get("/organizations/{$other->ulid}/logo/{$media->ulid}")->assertNotFound();
    CrossFixtures::signIn($this->chief)->get("{$url}/preview")->assertNotFound();
});

test('replacing the logo changes its URL and keeps the old one in history only', function () {
    uploadLogo($this, PersonFixtures::png())->assertSessionHasNoErrors();
    $first = currentLogo($this->organization);

    uploadLogo($this, PersonFixtures::jpegWithExif())->assertSessionHasNoErrors();
    $second = currentLogo($this->organization);

    expect($second->ulid)->not->toBe($first->ulid)
        ->and(Media::query()->where('collection_name', Organization::LOGO)->count())->toBe(1)
        ->and(Media::withTrashed()->where('collection_name', Organization::LOGO)->count())->toBe(2)
        // The replaced file is kept on disk.
        ->and(Storage::disk('evidence')->exists($first->getPathRelativeToRoot()))->toBeTrue();

    CrossFixtures::signIn($this->chief)->get("/organizations/{$this->organization->ulid}/logo/{$first->ulid}")->assertNotFound();
    CrossFixtures::signIn($this->chief)->get("/organizations/{$this->organization->ulid}/logo/{$second->ulid}")->assertOk();
    expect(Activity::query()->where('event', 'logo_added')->latest('id')->first()?->properties['replaced'])->toBe([$first->ulid]);
});

test('removing the logo soft-deletes it and logs a named event', function () {
    uploadLogo($this, PersonFixtures::png())->assertSessionHasNoErrors();
    $media = currentLogo($this->organization);

    CrossFixtures::signIn($this->chief)->delete("/organizations/{$this->organization->ulid}/logo")->assertRedirect();

    expect(currentLogo($this->organization))->toBeNull()
        ->and(Media::withTrashed()->whereKey($media->id)->first()?->trashed())->toBeTrue()
        ->and(Activity::query()->where('event', 'logo_removed')->sole()->properties['logo'])->toBe([$media->ulid]);
    CrossFixtures::signIn($this->chief)->get("/organizations/{$this->organization->ulid}/logo/{$media->ulid}")->assertNotFound();
});

test('only users who may manage organizations upload or remove logos', function () {
    $admin = $this->world->users['super_admin'];

    CrossFixtures::signIn($admin)->post("/organizations/{$this->organization->ulid}/logo", ['logo' => PersonFixtures::png()])->assertForbidden();
    CrossFixtures::signIn($admin)->delete("/organizations/{$this->organization->ulid}/logo")->assertForbidden();
    expect(currentLogo($this->organization))->toBeNull();
});

test('the logo appears in lists, pages, pickers and search, with a placeholder otherwise', function () {
    uploadLogo($this, PersonFixtures::png())->assertSessionHasNoErrors();
    $media = currentLogo($this->organization);
    $thumb = "/organizations/{$this->organization->ulid}/logo/{$media->ulid}/thumb";
    $plain = Organization::factory()->create(['name' => 'Gulf Haulage']);
    $site = OrganizationSite::factory()->in($this->world->lgas['A1a'])->of($this->organization)->create();
    // A user in another state, with no site of the organization in reach.
    $elsewhere = CrossFixtures::chiefOf($this->world->lgas['B1']);

    CrossFixtures::signIn($elsewhere)->get('/organizations?q=Gulf')->assertInertia(fn (Assert $page) => $page
        ->where('organizations.data', fn ($rows) => collect($rows)->firstWhere('name', 'Gulf of Guinea Petroleum Ltd')['logo']['thumb'] === $thumb
            && collect($rows)->firstWhere('name', 'Gulf Haulage')['logo'] === null));

    CrossFixtures::signIn($elsewhere)->get("/organizations/{$this->organization->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('organization.logo.thumb', $thumb)
        ->where('organization.logo.full', "/organizations/{$this->organization->ulid}/logo/{$media->ulid}"));

    CrossFixtures::signIn($this->chief)->get("/organization-sites/{$site->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('site.organization.logo.thumb', $thumb));

    CrossFixtures::signIn($this->chief)->get("/lgas/{$this->world->lgas['A1a']->ulid}?tab=organizations")->assertInertia(fn (Assert $page) => $page
        ->where('organizations.sites.0.organization.logo.thumb', $thumb));

    $picked = CrossFixtures::signIn($elsewhere)->postJson('/pickers/organizations', ['q' => 'Gulf'])->assertOk()->json('results');
    expect(collect($picked)->firstWhere('label', 'Gulf of Guinea Petroleum Ltd')['image'])->toBe($thumb)
        ->and(collect($picked)->firstWhere('label', 'Gulf Haulage')['image'])->toBeNull();

    $hits = CrossFixtures::signIn($elsewhere)->getJson('/search/quick?q=Gulf+of+Guinea')->assertOk()->json('sections');
    $organizations = collect($hits)->firstWhere('key', 'organizations');
    expect(collect($organizations['hits'])->firstWhere('title', 'Gulf of Guinea Petroleum Ltd')['image'])->toBe($thumb);
});
