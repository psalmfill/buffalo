<?php

use App\Actions\Reports\RequestReport;
use App\Enums\IncidentStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Models\Incident;
use App\Models\ReportRun;
use App\Models\User;
use App\Support\Incidents\IncidentFilters;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Flashpoints\FlashpointFixtures as F;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Threats\ThreatFixtures as T;

/*
| Phase 11B.3/11B.4: search indexes plain text only; PDFs render the
| formatting; exports carry no markup; the CSP is unchanged.
*/

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->lga = $this->world->lgas['A1a'];
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->incident = W::at(W::draft($this->lga, $this->chief, [
        'title' => 'Travellers taken on the expressway',
        'description' => '<p><strong>Ambushed</strong> near the creek.</p><ul><li><p>Tall suspect</p></li></ul>',
        'action_taken' => '<p>Roadblock <em>mounted</em></p>',
    ]), IncidentStatus::Verified);
});

function incidentTitles(User $user, string $q): array
{
    return app(JurisdictionContext::class)->actingAs($user, fn () => IncidentFilters::from(['q' => $q])->apply(Incident::query(), $user)->pluck('title')->all());
}

test('search vectors index the plain text, never the markup', function () {
    $threatType = T::crimeType();
    CrossFixtures::system();
    T::threat($this->lga, ['title' => 'Market threat', 'description' => '<p><strong>Bandits</strong> massing</p>']);
    F::flashpoint($this->lga, ['name' => 'Creek crossing', 'description' => '<p><strong>Smuggling</strong> route</p>', 'threat_type_id' => $threatType->id]);

    expect(incidentTitles($this->sd, 'ambushed'))->toBe(['Travellers taken on the expressway'])
        ->and(incidentTitles($this->sd, 'suspect'))->toBe(['Travellers taken on the expressway'])
        ->and(incidentTitles($this->sd, 'strong'))->toBe([])
        ->and(incidentTitles($this->sd, '<p>'))->toBe([])
        ->and(incidentTitles($this->sd, 'li'))->toBe([]);

    $search = fn (string $q) => collect(CrossFixtures::signIn($this->sd)->getJson('/search/quick?q='.urlencode($q))->json('sections'))->pluck('key')->all();

    expect($search('bandits'))->toContain('threats')
        ->and($search('smuggling'))->toContain('flashpoints')
        ->and($search('strong'))->toBe([]);

    // The vectors are built from the plain text copies.
    foreach (['incidents', 'threat_reports', 'flashpoints'] as $table) {
        expect(DB::table('information_schema.columns')->where('table_name', $table)->where('column_name', 'search_vector')->value('generation_expression'))
            ->toContain('description_text');
    }
});

test('the incident PDF renders the formatting', function () {
    Storage::fake('reports');
    Http::fake(['*' => Http::response("%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n%%EOF", 200, ['Content-Type' => 'application/pdf'])]);
    W::step($this->sd, $this->incident, 'updates', ['type' => 'follow_up', 'body' => '<p>Second victim <strong>found</strong></p>', 'classification' => 'restricted'])->assertSessionHasNoErrors();
    CrossFixtures::system();
    // A follow-up written before Phase 11B (plain text, format NULL).
    DB::table('incident_updates')->insert([
        'ulid' => strtolower((string) Str::ulid()), 'incident_id' => $this->incident->id, 'state_id' => $this->incident->state_id, 'lga_id' => $this->incident->lga_id,
        'type' => 'follow_up', 'body' => "Old note <b>raw</b>\nsecond line", 'classification' => 'restricted', 'created_by' => $this->chief->id, 'created_at' => now()->subDay(),
    ]);

    $run = app(JurisdictionContext::class)->actingAs($this->sd, fn () => app(RequestReport::class)($this->sd, ReportType::Incident, ReportFormat::Pdf, ['incident' => $this->incident->ulid]));
    expect(ReportRun::query()->findOrFail($run->id)->file_path)->not->toBeNull();

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        return str_contains($body, '<div class="rich"><p><strong>Ambushed</strong> near the creek.</p><ul><li><p>Tall suspect</p></li></ul></div>')
            && str_contains($body, '<p>Roadblock <em>mounted</em></p>')
            && str_contains($body, '<p>Second victim <strong>found</strong></p>')
            // The old follow-up: escaped plain text, shown with its line breaks.
            && str_contains($body, '<div class="pre">Old note &lt;b&gt;raw&lt;/b&gt;'."\nsecond line</div>");
    });
});

test('incident list exports carry no markup', function () {
    Storage::fake('reports');
    $run = app(JurisdictionContext::class)->actingAs($this->sd, fn () => app(RequestReport::class)($this->sd, ReportType::IncidentList, ReportFormat::Csv, ['filters' => []], stepUpConfirmed: true));
    $csv = (string) Storage::disk('reports')->get((string) ReportRun::query()->findOrFail($run->id)->file_path);

    expect($csv)->toContain('Travellers taken on the expressway')
        ->not->toContain('<p>')
        ->not->toContain('<strong>')
        ->not->toContain('Ambushed');
});

test('pages with the editor keep the strict CSP and render stored rich text as data', function () {
    $response = CrossFixtures::signIn($this->chief)->get("/incidents/{$this->incident->ulid}/edit");
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toMatch("/script-src 'self' 'nonce-[^']+'/")
        ->not->toContain("'unsafe-eval'")
        ->and(preg_match("/script-src[^;]*'unsafe-inline'/", $csp))->toBe(0)
        // Stored HTML travels as JSON page props, never as markup in the page.
        ->and((string) $response->getContent())->not->toContain('<strong>Ambushed</strong>');
});
