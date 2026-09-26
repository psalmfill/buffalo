<?php

namespace App\Http\Controllers\Persons;

use App\Actions\Persons\AddPersonPhoto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Persons\PersonPhotoRequest;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Person photos. Files are never served by URL from storage: they are
 * streamed here after the person policy check ({person} is bound through
 * both scopes, so out-of-reach persons are 404), with no caching.
 */
class PersonPhotoController extends Controller
{
    /** Conversions that may be requested. */
    public const CONVERSIONS = ['thumb', 'preview'];

    public function store(PersonPhotoRequest $request, Person $person, AddPersonPhoto $add): RedirectResponse
    {
        $this->authorize('update', $person);

        /** @var User $actor */
        $actor = $request->user();
        /** @var UploadedFile $photo */
        $photo = $request->file('photo');

        $add($person, $photo, $actor);

        return redirect()->route('persons.show', ['person' => $person, 'tab' => 'photos'])
            ->with('toast', ['type' => 'success', 'message' => 'Photo added.']);
    }

    public function show(Request $request, Person $person, Media $media, ?string $conversion = null): StreamedResponse
    {
        $this->authorize('view', $person);

        abort_unless(
            $media->model_type === $person->getMorphClass()
                && (int) $media->model_id === $person->id
                && $media->collection_name === Person::PHOTOS,
            404,
        );
        abort_unless($conversion === null || in_array($conversion, self::CONVERSIONS, true), 404);

        // Until the queued conversion exists, serve the (sanitised) original.
        $use = $conversion !== null && $media->hasGeneratedConversion($conversion) ? $conversion : '';
        $mime = $use === '' ? (string) $media->mime_type : 'image/jpeg';
        $stream = $media->stream($use);

        return response()->stream(function () use ($stream): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="photo"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
