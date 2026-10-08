<?php

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Help\Manual;
use App\Support\Help\ManualLibrary;
use App\Support\Help\MarkdownRenderer;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| Help and manuals (Phase 9B.4): role-appropriate manuals, sanitised
| rendering without external resources, contextual help that resolves, and
| printable cards with the RESTRICTED marking.
*/

const INTELLIGENCE_FACTORIES = ['directorGeneral', 'ddg', 'stateDirector', 'deputyStateDirector', 'lgaChief'];

function helpIndex(object $test, User $user): array
{
    $props = [];
    $test->actingAs($user)->get(route('help.index'))->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $page->component('help/index');
            $props = $page->toArray()['props'];
        });

    return $props;
}

test('every manual has valid front matter, and there is one manual and one card per role', function () {
    $library = app(ManualLibrary::class);
    $manuals = $library->all();

    expect(collect($manuals)->where('kind', 'start')->pluck('slug')->all())->toBe(['getting-started'])
        ->and(collect($manuals)->where('kind', 'roles')->map(fn (Manual $m) => $m->role)->sort()->values()->all())
        ->toEqual(collect(Role::cases())->sort()->values()->all())
        ->and(collect($manuals)->where('kind', 'cards')->map(fn (Manual $m) => $m->role)->sort()->values()->all())
        ->toEqual(collect(Role::cases())->sort()->values()->all())
        ->and(collect($manuals)->where('kind', 'guides')->pluck('slug')->sort()->values()->all())->toBe([
            'coverage-reports', 'evidence', 'flashpoints', 'merging-duplicates', 'persons-and-cross-state-requests',
            'reporting-incidents', 'reports-and-exports', 'threats-and-dissemination', 'what-is-logged',
        ]);
});

test('each intelligence role sees its own manual first, every guide, and never the super admin manual', function (string $factory, Role $role) {
    $user = User::factory()->{$factory}()->create();
    $props = helpIndex($this, $user);

    expect($props['roles'][0]['own'])->toBeTrue()
        ->and(collect($props['roles'])->pluck('slug')->all())->not->toContain('super-admin')
        ->and(collect($props['roles']))->toHaveCount(5)
        ->and(collect($props['guides']))->toHaveCount(9)
        ->and(collect($props['start'])->pluck('slug')->all())->toBe(['getting-started'])
        ->and(collect($props['cards'])->pluck('role')->all())->not->toContain('super_admin')
        ->and(collect($props['cards'])->firstWhere('own', true)['role'])->toBe($role->value);

    $this->actingAs($user)->get(route('help.show', ['kind' => 'roles', 'slug' => 'super-admin']))->assertNotFound();
})->with([
    ['directorGeneral', Role::DirectorGeneral],
    ['ddg', Role::DeputyDirectorGeneral],
    ['stateDirector', Role::StateDirector],
    ['deputyStateDirector', Role::DeputyStateDirector],
    ['lgaChief', Role::LgaSecurityChief],
]);

test('the super admin sees getting started, their own manual and card, and what is logged, but no intelligence guides', function () {
    $admin = User::factory()->superAdmin()->create();
    $props = helpIndex($this, $admin);

    expect(collect($props['roles'])->pluck('slug')->all())->toBe(['super-admin'])
        ->and(collect($props['guides'])->pluck('slug')->all())->toBe(['what-is-logged'])
        ->and(collect($props['cards'])->pluck('role')->all())->toBe(['super_admin']);

    $this->actingAs($admin)->get(route('help.show', ['kind' => 'guides', 'slug' => 'reporting-incidents']))->assertNotFound();
    $this->actingAs($admin)->get(route('help.show', ['kind' => 'roles', 'slug' => 'super-admin']))->assertOk();
});

test('help needs a signed-in user with a role', function () {
    $this->get(route('help.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('help.index'))->assertForbidden();
});

test('every link between manuals resolves to a manual', function () {
    $library = app(ManualLibrary::class);
    $slugs = collect($library->all())->reject(fn (Manual $m) => $m->kind === 'cards')->pluck('slug')->all();
    $problems = [];

    foreach ($library->all() as $manual) {
        preg_match_all('/\]\(([^)#\s]+\.md)(#[^)\s]*)?\)/', $manual->body, $links, PREG_SET_ORDER);
        foreach ($links as $link) {
            if (! in_array(basename($link[1], '.md'), $slugs, true)) {
                $problems[] = "{$manual->kind}/{$manual->slug}: {$link[1]}";
            }
        }
        // No external links or images in the source either.
        if (preg_match('/\]\((https?:|\/\/|mailto:)|!\[/', $manual->body) === 1) {
            $problems[] = "{$manual->kind}/{$manual->slug}: external link or image";
        }
    }

    expect($problems)->toBe([]);
});

test('every contextual help link points to an existing manual section', function () {
    $library = app(ManualLibrary::class);
    $renderer = app(MarkdownRenderer::class);
    $problems = [];
    $found = 0;

    foreach (File::allFiles(resource_path('js')) as $file) {
        if (! str_ends_with($file->getFilename(), '.tsx')) {
            continue;
        }
        $source = $file->getContents();
        // <HelpLink topic="..." section="..." /> and help={{ topic: '...', section: '...' }}
        preg_match_all('/topic(?:=|: )["\']([a-z]+)\/([a-z0-9-]+)["\'](?:[\s,]+section(?:=|: )["\']([a-z0-9-]+)["\'])?/', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $found++;
            $manual = $library->find($m[1], $m[2]);
            if ($manual === null) {
                $problems[] = "{$file->getRelativePathname()}: {$m[1]}/{$m[2]}";

                continue;
            }
            if (($m[3] ?? '') !== '' && ! in_array($m[3], array_column($renderer->render($manual)['toc'], 'id'), true)) {
                $problems[] = "{$file->getRelativePathname()}: {$m[1]}/{$m[2]}#{$m[3]}";
            }
        }
    }

    expect($found)->toBeGreaterThan(15)->and($problems)->toBe([]);
});

test('rendering strips raw HTML, scripts, images and external links, and links manuals internally', function () {
    $manual = new Manual('guides', 'test', 'Test', '', 'all', null, 1, <<<'MD'
        ## A section

        <script>alert(1)</script><img src="http://evil.example/x.png">
        Inline <b onclick="x()">html</b>.

        ![a photo](http://cdn.example/photo.jpg) ![local](photo.png)

        [Outside](https://example.com/page), [protocol-relative](//cdn.example/x),
        [script](javascript:alert(1)), [mail](mailto:a@b.c) and
        [evidence guide](evidence.md#chain-of-custody) and [here](#a-section).
        MD);

    $html = app(MarkdownRenderer::class)->render($manual)['html'];

    expect($html)->not->toContain('<script', '<img', 'onclick', 'http://', 'https://', '//cdn', 'javascript:', 'mailto:', 'evil.example')
        ->toContain('<h2 id="a-section">', 'Outside', 'protocol-relative', 'evidence guide')
        ->toContain('href="/help/guides/evidence#chain-of-custody"', 'href="#a-section"');
});

test('a manual page carries no external resource and keeps the security policy', function () {
    $response = $this->actingAs(User::factory()->stateDirector()->create())
        ->get(route('help.show', ['kind' => 'guides', 'slug' => 'reporting-incidents']));

    $response->assertOk()->assertHeader('Content-Security-Policy');
    $html = '';
    $response->assertInertia(function (Assert $page) use (&$html) {
        $html = $page->toArray()['props']['html'];
        $page->where('manual.title', 'Reporting and processing an incident')->has('toc');
    });

    expect($html)->not->toMatch('/(src|href)="(https?:)?\/\//')
        ->and($html)->not->toContain('<script', '<img', '<iframe', 'style=');
});

test('a quick reference card is printed through Gotenberg with the RESTRICTED marking and audited', function () {
    Http::fake(['*/forms/chromium/convert/html' => Http::response('%PDF-1.7 card', 200)]);
    $user = User::factory()->lgaChief()->create(['name' => 'Tunde Chief']);

    $response = $this->actingAs($user)->get(route('help.card', ['role' => 'lga_security_chief']));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
    expect($response->getContent())->toStartWith('%PDF');

    Http::assertSent(function (ClientRequest $request) {
        $body = collect($request->data())->pluck('contents')->implode("\n");

        return str_contains($body, 'RESTRICTED') && str_contains($body, 'Generated for Tunde Chief')
            && str_contains($body, 'Quick reference — LGA Security Chief') && ! preg_match('/(src|href)="https?:/', $body);
    });

    expect(Activity::query()->where('log_name', AuditLog::LOG_NAME)->where('event', 'help_card_printed')->where('causer_id', $user->id)->exists())->toBeTrue();
});

test('cards follow the manuals\' audience', function (string $factory, string $card) {
    Http::fake(['*' => Http::response('%PDF-1.7', 200)]);

    $this->actingAs(User::factory()->{$factory}()->create())->get(route('help.card', ['role' => $card]))->assertNotFound();
    Http::assertNothingSent();
})->with([
    'DG, super admin card' => ['directorGeneral', 'super_admin'],
    'super admin, intelligence card' => ['superAdmin', 'state_director'],
    'unknown role' => ['superAdmin', 'nobody'],
]);

test('the manuals are bundled into the image', function () {
    $ignore = (string) file_get_contents(base_path('.dockerignore'));

    expect($ignore)->toContain("\ndocs\n")->toContain('!docs/manuals');
});
