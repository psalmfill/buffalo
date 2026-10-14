<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Support\Theme\Contrast;
use App\Support\Theme\FixedColours;
use App\Support\Theme\Theme;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 11A.3: configurable brand, chart and KPI colours with contrast
| checks; severity, classification and urgency colours stay fixed.
*/

beforeEach(function () {
    CrossFixtures::system();
    Theme::forgetCache();
    $this->world = JurisdictionWorld::build();
    $this->admin = $this->world->users['super_admin'];
    $this->dg = $this->world->users['director_general'];
});

/** A complete, passing theme with one colour changed. */
function themeWith(string $path, string $colour): array
{
    $theme = Theme::defaults();
    Arr::set($theme, $path, $colour);

    return $theme;
}

test('the Theme page and its actions are for theme.manage holders only', function (string $who, int $status) {
    CrossFixtures::signIn($this->world->users[$who])->get('/system/theme')->assertStatus($status);
    CrossFixtures::signIn($this->world->users[$who])->put('/system/theme', Theme::defaults())->assertStatus($status === 200 ? 302 : $status);
    CrossFixtures::signIn($this->world->users[$who])->delete('/system/theme')->assertStatus($status === 200 ? 302 : $status);
})->with([
    ['super_admin', 200],
    ['director_general', 200],
    ['ddg_national', 403],
    ['state_director', 403],
    ['deputy_state_director', 403],
    ['lga_chief', 403],
]);

test('the page shows the current colours, the defaults and the fixed colours read-only', function () {
    CrossFixtures::signIn($this->admin)->get('/system/theme')->assertInertia(fn (Assert $page) => $page
        ->component('system/theme')
        ->where('theme', Theme::defaults())
        ->where('customised', false)
        ->where('fixed.0.group', 'Severity')
        ->where('fixed.0.colours', fn ($colours) => collect($colours)->pluck('colour')->all() === array_values(FixedColours::SEVERITY)));
});

test('colours must be #rrggbb and every field is required', function () {
    CrossFixtures::signIn($this->admin)->put('/system/theme', themeWith('chart.light.2', 'red'))->assertSessionHasErrors('chart.light.2');
    CrossFixtures::signIn($this->admin)->put('/system/theme', themeWith('kpi.threats.dark', '#12345'))->assertSessionHasErrors('kpi.threats.dark');

    $missing = Theme::defaults();
    unset($missing['chart']['dark'][7]);
    CrossFixtures::signIn($this->admin)->put('/system/theme', $missing)->assertSessionHasErrors('chart.dark');

    expect(AppSetting::value(Theme::KEY))->toBeNull();
});

test('a colour without enough contrast is rejected with its ratio and the nearest passing colour', function () {
    $pale = '#ffe680';
    $ratio = Contrast::measured($pale, '#ffffff');
    $suggested = Contrast::suggest($pale, '#ffffff', Contrast::GRAPHIC);

    $response = CrossFixtures::signIn($this->admin)->put('/system/theme', themeWith('chart.light.3', $pale));
    $response->assertSessionHasErrors('chart.light.3');
    $message = session('errors')->first('chart.light.3');

    expect($ratio)->toBeLessThan(3.0)
        ->and($message)->toContain(sprintf('%.2f:1', $ratio))->toContain('at least 3:1')->toContain("Nearest passing colour: {$suggested}")
        ->and(Contrast::passes((string) $suggested, '#ffffff', Contrast::GRAPHIC))->toBeTrue();

    // Text colours need 4.5:1; dark mode is checked against the dark card.
    CrossFixtures::signIn($this->admin)->put('/system/theme', themeWith('kpi.incidents.light', '#3b82f6'))->assertSessionHasErrors('kpi.incidents.light');
    CrossFixtures::signIn($this->admin)->put('/system/theme', themeWith('brand.primary.dark', '#1e3a8a'))->assertSessionHasErrors('brand.primary.dark');
    expect(AppSetting::value(Theme::KEY))->toBeNull();
});

test('a valid theme is saved, cached, audit-logged with old and new values, and can be reset', function () {
    CrossFixtures::signIn($this->dg)->put('/system/theme', themeWith('chart.light.0', '#0F766E'))
        ->assertRedirect('/system/theme')->assertSessionHasNoErrors();

    expect(Theme::current()['chart']['light'][0])->toBe('#0f766e')
        ->and(Theme::chartPalette('light')[0])->toBe('#0f766e');

    $event = Activity::query()->where('event', 'theme_updated')->sole();
    expect($event->causer_id)->toBe($this->dg->id)
        ->and($event->properties['old']['chart']['light'][0])->toBe('#2563eb')
        ->and($event->properties['new']['chart']['light'][0])->toBe('#0f766e');

    CrossFixtures::signIn($this->admin)->delete('/system/theme')->assertRedirect('/system/theme');
    expect(Theme::current())->toBe(Theme::defaults())
        ->and(Activity::query()->where('event', 'theme_reset')->sole()->properties['old']['chart']['light'][0])->toBe('#0f766e');
});

test('the theme is rendered as CSS custom properties in a style tag carrying the page nonce', function () {
    CrossFixtures::signIn($this->admin)->put('/system/theme', themeWith('chart.dark.1', '#fb923c'))->assertSessionHasNoErrors();

    $response = CrossFixtures::signIn($this->dg)->get('/dashboard?lens=off');
    $html = (string) $response->getContent();
    preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $nonce);

    expect($nonce[1] ?? null)->not->toBeNull()
        ->and($html)->toContain('<style nonce="'.$nonce[1].'" data-theme="buffalo">')
        ->and($html)->toContain('html.dark{')->toContain('--chart-palette-2:#fb923c;')
        ->and($html)->toContain('--chart-palette-1:#2563eb;')
        ->and($html)->toContain('--kpi-incidents:#1d4ed8;');
});

test('severity, classification and urgency colours are never part of a theme', function () {
    // Unknown keys are dropped; nothing but the configurable fields is stored.
    CrossFixtures::signIn($this->admin)->put('/system/theme', [...Theme::defaults(), 'severity' => ['critical' => '#00ff00']])->assertSessionHasNoErrors();

    expect(AppSetting::value(Theme::KEY))->not->toHaveKey('severity')
        ->and(Theme::css())->not->toContain('severity')->not->toContain('#00ff00');

    // The fixed colours match the frontend's constants.
    $severity = (string) file_get_contents(resource_path('js/components/incidents/incident-table.tsx'));
    $urgency = (string) file_get_contents(resource_path('js/components/threats/threat-display.tsx'));
    foreach (FixedColours::SEVERITY as $level => $colour) {
        expect($severity)->toContain("{$level}: '{$colour}'");
    }
    foreach (FixedColours::URGENCY as $level => $colour) {
        expect($urgency)->toContain("{$level}: '{$colour}'");
    }
    $banner = (string) file_get_contents(resource_path('js/components/system-banner.tsx'));
    expect($banner)->toContain("restricted: 'bg-amber-300")->toContain("confidential: 'bg-blue-700")->toContain("secret: 'bg-red-700");
});

test('charts in PDF reports use the configured palette', function () {
    CrossFixtures::signIn($this->admin)->put('/system/theme', themeWith('chart.light.0', '#0f766e'))->assertSessionHasNoErrors();

    $html = view('reports.layout', ['title' => 'Test', 'markings' => (object) ['watermark' => 'W', 'reference' => 'R']])->render();

    expect($html)->toContain('var palette = ["#0f766e","#dc2626"');
});

test('the defaults pass their own contrast checks and match app.css', function () {
    expect(Theme::contrastErrors(Theme::defaults()))->toBe([]);

    $css = (string) file_get_contents(resource_path('css/app.css'));
    foreach (Theme::defaults()['chart']['light'] as $i => $colour) {
        expect($css)->toContain('--chart-palette-'.($i + 1).": {$colour};");
    }
    foreach (Theme::KPI_TYPES as $type) {
        expect($css)->toContain("--kpi-{$type}: ".Theme::defaults()['kpi'][$type]['light'].';')
            ->toContain("--kpi-{$type}: ".Theme::defaults()['kpi'][$type]['dark'].';');
    }
});

test('contrast arithmetic follows WCAG', function () {
    expect(round(Contrast::ratio('#000000', '#ffffff'), 2))->toBe(21.0)
        ->and(round(Contrast::ratio('#767676', '#ffffff'), 2))->toBe(4.54)
        ->and(Contrast::suggest('#2563eb', '#ffffff', 4.5))->toBe('#2563eb')
        ->and(Contrast::passes((string) Contrast::suggest('#888888', '#0a0a0a', 4.5), '#0a0a0a', 4.5))->toBeTrue();
});

test('a stored theme that no longer passes is ignored in favour of the defaults', function () {
    AppSetting::put(Theme::KEY, themeWith('chart.light.0', '#ffffff'), User::factory()->superAdmin()->create());
    Theme::forgetCache();

    expect(Theme::current())->toBe(Theme::defaults());
});
