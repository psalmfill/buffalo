<?php

namespace App\Services\Reports\Builders;

use App\Enums\AccessAction;
use App\Http\Presenters\OrganizationPresenter;
use App\Models\Media;
use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\OrganizationSiteFormation;
use App\Models\OrganizationSitePhoto;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

/**
 * One organization site as a PDF (Phase 11E): details, security notes,
 * government security (formations the requester can see), contacts by
 * name and position only (never phone or email), and the cover with up to
 * six more photos (Phase 11D). Photo files are sent with the request, so
 * nothing is fetched.
 */
final class OrganizationSiteReportBuilder implements ReportBuilder
{
    /** The cover and up to six others. */
    public const PHOTOS = 7;

    public function __construct(private readonly PdfRenderer $pdf) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $site = OrganizationSite::query()->withCoordinates()->where('ulid', (string) $run->parameters['site'])->first();

        if ($site === null || ! $user->can('view', $site)) {
            throw new AuthorizationException('The site is no longer visible to the requester.');
        }

        $site->recordAccess(AccessAction::Print, 'report');
        $site->load(['organization.sector', 'lga', 'state', 'community', 'securityProvider.sector']);
        $levels = new LevelTracker;
        $levels->see($site->classificationLevel());

        $formations = OrganizationSiteFormation::query()
            ->with(['formation' => fn ($q) => $q->withCoordinates()->with(['agency', 'lga', 'state'])])
            ->where('organization_site_id', $site->id)
            ->get();
        $levels->seeAll($formations->map(fn (OrganizationSiteFormation $l) => $l->formation->classificationLevel()));

        // Names and positions only: the contacts' phone and email never leave the page.
        $contacts = OrganizationContact::query()->where('organization_site_id', $site->id)
            ->orderByDesc('is_primary')->orderByDesc('is_management')->orderByDesc('is_security_contact')->orderBy('name')
            ->get()
            ->filter(fn (OrganizationContact $c) => $user->can('view', $c))
            ->map(fn (OrganizationContact $c) => [
                'name' => $c->name,
                'position' => $c->position,
                'flags' => array_values(array_filter([$c->is_primary ? 'Primary' : null, $c->is_management ? 'Management' : null, $c->is_security_contact ? 'Security' : null])),
            ])->values()->all();

        [$photos, $files] = $this->photos($site);

        return $this->pdf->render('reports.organization-site', [
            'site' => OrganizationPresenter::siteDetail($site),
            'formations' => $formations->map(fn (OrganizationSiteFormation $l) => OrganizationPresenter::formationLink($l))->all(),
            'contacts' => $contacts,
            'photos' => $photos,
        ], 'Organization site — '.$site->name, $run, $user, $levels, $files);
    }

    /**
     * The cover and the next photos, as files sent with the request.
     *
     * @return array{0: list<array{file: string, caption: string|null, taken_on: string|null, cover: bool}>, 1: array<string, string>}
     */
    private function photos(OrganizationSite $site): array
    {
        $photos = [];
        $files = [];

        foreach (OrganizationSitePhoto::query()->with('file')->where('organization_site_id', $site->id)->galleryOrder()->limit(self::PHOTOS)->get() as $index => $photo) {
            $media = $photo->file;

            if (! $media instanceof Media) {
                continue;
            }

            $conversion = $media->hasGeneratedConversion('preview') ? 'preview' : '';
            $contents = Storage::disk($media->disk)->get($media->getPathRelativeToRoot($conversion));

            if (! is_string($contents)) {
                continue;
            }

            $name = 'site-photo-'.($index + 1).'.'.pathinfo((string) $media->file_name, PATHINFO_EXTENSION);
            $files[$name] = $contents;
            $photos[] = ['file' => $name, 'caption' => $photo->caption, 'taken_on' => $photo->taken_on?->format('j M Y'), 'cover' => $photo->is_cover];
        }

        return [$photos, $files];
    }
}
