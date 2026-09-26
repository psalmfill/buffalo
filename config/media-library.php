<?php

use App\Models\Media;
use App\Support\Media\EvidencePathGenerator;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Image;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Webp;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Spatie\MediaLibrary\Downloaders\DefaultDownloader;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Models\Observers\MediaObserver;
use Spatie\MediaLibrary\ResponsiveImages\Jobs\GenerateResponsiveImagesJob;
use Spatie\MediaLibrary\ResponsiveImages\TinyPlaceholderGenerator\Blurred;
use Spatie\MediaLibrary\ResponsiveImages\WidthCalculator\FileSizeOptimizedWidthCalculator;
use Spatie\MediaLibrary\Support\FileNamer\DefaultFileNamer;
use Spatie\MediaLibrary\Support\FileRemover\DefaultFileRemover;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

/*
|--------------------------------------------------------------------------
| spatie/laravel-medialibrary (Phase 3A)
|--------------------------------------------------------------------------
|
| All media (person photos now, incident evidence in Phase 5) lives on the
| private `evidence` disk, conversions included. There are no public URLs:
| files are streamed only through authorised controllers, and an
| architecture test forbids getUrl()/getFullUrl() in app/ and resources/.
|
| No external tools or downloads: no image optimiser binaries, no PDF, SVG
| or video generators, and media is never added from a URL.
|
*/

return [

    'disk_name' => 'evidence',

    'conversions_disk_name' => 'evidence',

    'max_file_size' => 1024 * 1024 * 10, // 10 MB

    'disallowed_extensions' => FileAdder::$defaultDisallowedExtensions,

    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],

    // Conversions (thumbnail, preview) are generated on the queue, after
    // the transaction that added the media commits.
    'queue_connection_name' => env('QUEUE_CONNECTION', 'sync'),
    'queue_name' => env('MEDIA_QUEUE', ''),
    'queue_conversions_by_default' => true,
    'queue_conversions_after_database_commit' => true,

    // Adds a ULID (used in URLs) and hides the package's URL attributes.
    'media_model' => Media::class,

    'media_observer' => MediaObserver::class,

    'use_default_collection_serialization' => false,

    'temporary_upload_model' => null,

    'enable_temporary_uploads_session_affinity' => false,

    'generate_thumbnails_for_temporary_uploads' => false,

    'file_namer' => DefaultFileNamer::class,

    // Directories named by the media ULID, never the numeric id.
    'path_generator' => EvidencePathGenerator::class,

    'file_remover_class' => DefaultFileRemover::class,

    'custom_path_generators' => [],

    // Required by the package; never called (no public URLs).
    'url_generator' => DefaultUrlGenerator::class,

    'moves_media_on_update' => false,

    'version_urls' => false,

    // No optimiser binaries: nothing is shelled out.
    'image_optimizers' => [],

    'image_generators' => [
        Image::class,
        Webp::class,
    ],

    'temporary_directory_path' => null,

    'image_driver' => 'gd',

    'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
    'ffmpeg_timeout' => 900,
    'ffmpeg_threads' => 0,

    'jobs' => [
        'perform_conversions' => PerformConversionsJob::class,
        'generate_responsive_images' => GenerateResponsiveImagesJob::class,
    ],

    // Only for addMediaFromUrl(), which BUFFALO never uses (no outbound
    // requests at runtime).
    'media_downloader' => DefaultDownloader::class,
    'media_downloader_ssl' => true,

    'temporary_url_default_lifetime' => 5,

    'remote' => [
        'extra_headers' => [],
    ],

    'responsive_images' => [
        'width_calculator' => FileSizeOptimizedWidthCalculator::class,
        'use_tiny_placeholders' => false,
        'tiny_placeholder_generator' => Blurred::class,
    ],

    'enable_vapor_uploads' => false,

    'default_loading_attribute_value' => null,

    'prefix' => '',

    'force_lazy_loading' => true,
];
