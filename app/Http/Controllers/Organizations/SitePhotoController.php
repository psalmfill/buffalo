<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Organizations\SitePhotos\AddSitePhoto;
use App\Actions\Organizations\SitePhotos\DeleteSitePhoto;
use App\Actions\Organizations\SitePhotos\ReorderSitePhotos;
use App\Actions\Organizations\SitePhotos\SetSiteCoverPhoto;
use App\Actions\Organizations\SitePhotos\UpdateSitePhoto;
use App\Enums\AccessAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Http\Requests\Organizations\SitePhotoRequest;
use App\Models\Media;
use App\Models\OrganizationSite;
use App\Models\OrganizationSitePhoto;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Site photos (Phase 11D). {site} and {photo} are bound through their
 * scopes, so a photo is reachable exactly when its site is (anything else
 * is 404). Viewing needs `view` on the site, managing `update`. Files are
 * streamed here, never served from storage; views of confidential and
 * secret sites' photos are access-logged on the site and never cached.
 */
class SitePhotoController extends Controller
{
    public function store(SitePhotoRequest $request, OrganizationSite $site, AddSitePhoto $add): RedirectResponse
    {
        $this->authorize('update', $site);

        /** @var UploadedFile $file */
        $file = $request->file('photo');
        $add($site, $file, $request->caption(), $request->takenOn(), $this->actor($request));

        return $this->back($site, 'Photo added.');
    }

    public function update(SitePhotoRequest $request, OrganizationSite $site, OrganizationSitePhoto $photo, UpdateSitePhoto $update): RedirectResponse
    {
        $this->authorize('update', $site);
        $this->belongs($site, $photo);

        $update($photo, $request->caption(), $request->takenOn(), $this->actor($request));

        return $this->back($site, 'Photo saved.');
    }

    public function cover(Request $request, OrganizationSite $site, OrganizationSitePhoto $photo, SetSiteCoverPhoto $cover): RedirectResponse
    {
        $this->authorize('update', $site);
        $this->belongs($site, $photo);

        $cover($photo, $this->actor($request));

        return $this->back($site, 'Cover photo set.');
    }

    public function reorder(Request $request, OrganizationSite $site, ReorderSitePhotos $reorder): RedirectResponse
    {
        $this->authorize('update', $site);
        $data = $request->validate([
            'order' => ['required', 'array', 'max:500'],
            'order.*' => ['required', 'string', 'size:26'],
        ]);

        /** @var list<string> $order */
        $order = array_values($data['order']);
        $reorder($site, $order, $this->actor($request));

        return $this->back($site, 'Photos reordered.');
    }

    public function destroy(DeleteRecordRequest $request, OrganizationSite $site, OrganizationSitePhoto $photo, DeleteSitePhoto $delete): RedirectResponse
    {
        $this->authorize('update', $site);
        $this->belongs($site, $photo);

        $delete($photo, (string) $request->validated('reason'), $this->actor($request));

        return $this->back($site, 'Photo deleted.');
    }

    public function thumb(OrganizationSite $site, OrganizationSitePhoto $photo): StreamedResponse
    {
        return $this->serve($site, $photo, 'thumb');
    }

    public function preview(OrganizationSite $site, OrganizationSitePhoto $photo): StreamedResponse
    {
        return $this->serve($site, $photo, 'preview');
    }

    public function original(OrganizationSite $site, OrganizationSitePhoto $photo): StreamedResponse
    {
        return $this->serve($site, $photo, 'original');
    }

    private function serve(OrganizationSite $site, OrganizationSitePhoto $photo, string $variant): StreamedResponse
    {
        $this->authorize('view', $site);
        $this->belongs($site, $photo);

        /** @var Media|null $media */
        $media = $photo->file;
        abort_if($media === null, 404);

        if ($site->logsViews()) {
            $site->recordAccess($variant === 'original' ? AccessAction::Download : AccessAction::View, "photo:{$variant}:{$photo->ulid}");
        }

        // Until the queued conversion exists, serve the (sanitised) original.
        $use = $variant !== 'original' && $media->hasGeneratedConversion($variant) ? $variant : '';
        $stream = $media->stream($use);
        $extension = pathinfo((string) $media->file_name, PATHINFO_EXTENSION);

        return response()->stream(function () use ($stream): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            // Conversions keep the original's format.
            'Content-Type' => (string) $media->mime_type,
            'Content-Disposition' => $variant === 'original' ? "attachment; filename=\"site-photo.{$extension}\"" : 'inline; filename="photo"',
            'Cache-Control' => $site->logsViews() ? 'private, no-store' : 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** The photo must be one of this site's (both are already in reach). */
    private function belongs(OrganizationSite $site, OrganizationSitePhoto $photo): void
    {
        abort_unless($photo->organization_site_id === $site->id, 404);
    }

    private function back(OrganizationSite $site, string $message): RedirectResponse
    {
        return redirect()->route('organization-sites.show', $site)->with('toast', ['type' => 'success', 'message' => $message]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
