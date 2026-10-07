<?php

use App\Enums\Classification;
use App\Models\Media;
use App\Models\Person;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\PersonFixtures;

beforeEach(function () {
    Storage::fake('evidence');
});

function photoWorld(): array
{
    $world = JurisdictionWorld::build();
    $person = Person::factory()->in($world->lgas['A1a'])->create();

    return [$world, $person];
}

test('an uploaded photo is stored on the evidence disk without its EXIF metadata', function () {
    [$world, $person] = photoWorld();

    $this->actingAs($world->users['lga_chief'])
        ->post("/persons/{$person->ulid}/photos", ['photo' => PersonFixtures::jpegWithExif()])
        ->assertSessionHasNoErrors();

    PersonFixtures::system();
    $media = Media::query()->sole();
    $stored = Storage::disk('evidence')->path($media->getPathRelativeToRoot());

    expect($media->disk)->toBe('evidence')
        ->and($media->conversions_disk)->toBe('evidence')
        ->and($media->file_name)->toBe('photo.jpg')
        ->and($media->name)->toBe('photo')
        ->and(file_exists($stored))->toBeTrue()
        // The directory is the media ULID, never a numeric id.
        ->and($media->getPathRelativeToRoot())->toBe('photos/'.strtolower($media->ulid).'/photo.jpg');

    $bytes = (string) file_get_contents($stored);
    $exif = @exif_read_data($stored);

    expect($bytes)->not->toContain('BUFFALOGPSMARKER')
        ->and($bytes)->not->toContain('Adeyemi')
        ->and(is_array($exif) ? array_intersect_key($exif, array_flip(['Make', 'GPSLatitudeRef', 'GPS_IFD_Pointer'])) : [])->toBe([]);

    // Conversions exist (queue is sync in tests), also without metadata.
    foreach (['thumb', 'preview'] as $conversion) {
        $path = Storage::disk('evidence')->path($media->getPathRelativeToRoot($conversion));

        expect($media->fresh()?->hasGeneratedConversion($conversion))->toBeTrue()
            ->and((string) file_get_contents($path))->not->toContain('BUFFALOGPSMARKER');
    }

    expect(Activity::query()->where('event', 'photo_added')->sole()->properties['photo'])->toBe($media->ulid);
});

test('photos are validated by content, not by name', function (Closure $file, bool $ok) {
    [$world, $person] = photoWorld();

    $response = $this->actingAs($world->users['lga_chief'])->post("/persons/{$person->ulid}/photos", ['photo' => $file()]);

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('photo');
})->with([
    'jpeg' => [fn () => PersonFixtures::jpegWithExif(), true],
    'png' => [fn () => PersonFixtures::png(), true],
    'php named .jpg' => [fn () => PersonFixtures::fakeJpeg(), false],
    'text named .jpg' => [fn () => PersonFixtures::fakeJpeg('just text'), false],
    'svg' => [fn () => UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), false],
    'gif' => [fn () => UploadedFile::fake()->image('photo.gif'), false],
    'too large' => [fn () => UploadedFile::fake()->image('photo.jpg')->size(10241), false],
]);

test('the newest photo is current and previous photos are kept', function () {
    [$world, $person] = photoWorld();
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->post("/persons/{$person->ulid}/photos", ['photo' => PersonFixtures::jpegWithExif()]);
    $this->actingAs($chief)->post("/persons/{$person->ulid}/photos", ['photo' => PersonFixtures::png()]);

    PersonFixtures::system();
    $newest = Media::query()->latest('id')->firstOrFail();

    expect(Media::query()->count())->toBe(2)
        ->and($person->fresh()?->currentPhoto()?->ulid)->toBe($newest->ulid);

    $this->actingAs($chief)->get("/persons/{$person->ulid}?tab=photos")
        ->assertInertia(fn ($page) => $page
            ->has('photos', 2)
            ->where('photos.0.ulid', $newest->ulid)
            ->where('photos.0.current', true)
            ->where('photos.1.current', false)
            ->where('photo.ulid', $newest->ulid));
});

test('photos are streamed only through the authorised route, privately', function (string $conversion) {
    [$world, $person] = photoWorld();
    $this->actingAs($world->users['lga_chief'])->post("/persons/{$person->ulid}/photos", ['photo' => PersonFixtures::jpegWithExif()]);
    PersonFixtures::system();
    $media = Media::query()->sole();
    $this->flushSession();

    $response = $this->actingAs($world->users['state_director'])->get("/persons/{$person->ulid}/photos/{$media->ulid}{$conversion}");

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Content-Disposition', 'inline; filename="photo"')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->streamedContent())->toStartWith("\xFF\xD8");
})->with(['' => '', 'thumb' => '/thumb', 'preview' => '/preview']);

test('out-of-jurisdiction users get 404 for the person and the photo', function (string $who) {
    [$world, $person] = photoWorld();
    $this->actingAs($world->users['lga_chief'])->post("/persons/{$person->ulid}/photos", ['photo' => PersonFixtures::jpegWithExif()]);
    PersonFixtures::system();
    $media = Media::query()->sole();
    $this->flushSession();

    $this->actingAs($world->users[$who])->get("/persons/{$person->ulid}")->assertNotFound();
    $this->actingAs($world->users[$who])->get("/persons/{$person->ulid}/photos/{$media->ulid}")->assertNotFound();
    $this->actingAs($world->users[$who])->get("/persons/{$person->ulid}/photos/{$media->ulid}/thumb")->assertNotFound();
})->with(['ddg_zone_b', 'super_admin', 'no_role']);

test('a photo is only served under its own person, and unknown conversions are 404', function () {
    [$world, $person] = photoWorld();
    $other = Person::factory()->in($world->lgas['A1a'])->create();
    $chief = $world->users['lga_chief'];
    $this->actingAs($chief)->post("/persons/{$person->ulid}/photos", ['photo' => PersonFixtures::jpegWithExif()]);
    PersonFixtures::system();
    $media = Media::query()->sole();

    $this->actingAs($chief)->get("/persons/{$other->ulid}/photos/{$media->ulid}")->assertNotFound();
    $this->actingAs($chief)->get("/persons/{$person->ulid}/photos/{$media->ulid}/original")->assertNotFound();
});

test('a classified person\'s photo is 404 for users below the level', function () {
    [$world] = photoWorld();
    $secret = Person::factory()->in($world->lgas['A1a'])->classified(Classification::Secret)->create();
    $this->actingAs($world->users['state_director'])->post("/persons/{$secret->ulid}/photos", ['photo' => PersonFixtures::png()])->assertSessionHasNoErrors();
    PersonFixtures::system();
    $media = Media::query()->sole();
    $this->flushSession();

    $this->actingAs($world->users['deputy_state_director'])->get("/persons/{$secret->ulid}/photos/{$media->ulid}")->assertNotFound();
});

test('media is never given a public URL anywhere in the code', function () {
    $violations = [];

    foreach (['app', 'resources'] as $directory) {
        foreach (File::allFiles(base_path($directory)) as $file) {
            if (! in_array($file->getExtension(), ['php', 'ts', 'tsx', 'js'], true)) {
                continue;
            }

            // MarkdownRenderer reads CommonMark link nodes (Link::getUrl), not media (Phase 9B.4).
            if (str_ends_with((string) $file->getRealPath(), 'app/Support/Help/MarkdownRenderer.php')) {
                continue;
            }

            if (preg_match('/\b(getUrl|getFullUrl|getTemporaryUrl)\s*\(/', $file->getContents()) === 1) {
                $violations[] = str_replace(base_path().'/', '', (string) $file->getRealPath());
            }
        }
    }

    expect($violations)->toBe([]);
});

test('the architecture check detects getUrl', function () {
    expect(preg_match('/\b(getUrl|getFullUrl|getTemporaryUrl)\s*\(/', '$media->getUrl("thumb")'))->toBe(1)
        ->and(preg_match('/\b(getUrl|getFullUrl|getTemporaryUrl)\s*\(/', '$media->getFullUrl()'))->toBe(1);
});

test('serialised media carries no URL attributes', function () {
    [$world, $person] = photoWorld();
    $this->actingAs($world->users['lga_chief'])->post("/persons/{$person->ulid}/photos", ['photo' => PersonFixtures::png()]);
    PersonFixtures::system();

    expect(array_keys(Media::query()->sole()->toArray()))->not->toContain('original_url')->not->toContain('preview_url')->not->toContain('id');
});
