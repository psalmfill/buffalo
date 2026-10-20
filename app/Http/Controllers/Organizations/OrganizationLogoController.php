<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Organizations\AddOrganizationLogo;
use App\Actions\Organizations\RemoveOrganizationLogo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\OrganizationLogoRequest;
use App\Models\Media;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Organization logos (Phase 11D). A logo belongs to the national directory
 * entry, so every role that may view organizations may see it. Files are
 * streamed here, never served from storage. The URL carries the media ULID
 * (a new logo has a new URL; a replaced one is soft-deleted and 404), so
 * the response may be cached privately for a long time.
 */
class OrganizationLogoController extends Controller
{
    public function store(OrganizationLogoRequest $request, Organization $organization, AddOrganizationLogo $add): RedirectResponse
    {
        $this->authorize('update', $organization);

        /** @var UploadedFile $logo */
        $logo = $request->file('logo');
        $add($organization, $logo, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Logo saved.']);
    }

    public function destroy(Request $request, Organization $organization, RemoveOrganizationLogo $remove): RedirectResponse
    {
        $this->authorize('update', $organization);

        $remove($organization, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Logo removed.']);
    }

    public function show(Organization $organization, Media $media, ?string $conversion = null): StreamedResponse
    {
        $this->authorize('view', $organization);

        abort_unless(
            $media->model_type === $organization->getMorphClass()
                && (int) $media->model_id === $organization->id
                && $media->collection_name === Organization::LOGO,
            404,
        );
        abort_unless($conversion === null || $conversion === 'thumb', 404);

        $use = $conversion !== null && $media->hasGeneratedConversion($conversion) ? $conversion : '';
        $stream = $media->stream($use);

        return response()->stream(function () use ($stream): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            // Conversions keep the original's format.
            'Content-Type' => (string) $media->mime_type,
            'Content-Disposition' => 'inline; filename="logo"',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
