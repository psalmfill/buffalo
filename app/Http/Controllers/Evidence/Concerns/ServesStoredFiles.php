<?php

namespace App\Http\Controllers\Evidence\Concerns;

use App\Actions\Evidence\ScanQuarantinedEvidence;
use App\Enums\EvidenceType;
use App\Models\CoverageReportAttachment;
use App\Models\IncidentEvidence;
use App\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Sends stored originals and their conversions (Phase 5B; shared with
 * security report attachments, Phase 11G): never by URL from storage, with
 * no caching, `nosniff`, a sandboxing CSP, and generated file names (never
 * the uploader's). Quarantined and infected files are never served
 * (Phase 8B.4); until a conversion exists its route is 404, so the
 * original (which keeps its EXIF) is never shown in its place.
 */
trait ServesStoredFiles
{
    /** @var array<string, string> */
    private array $securityHeaders = [
        'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; sandbox",
    ];

    private function conversionResponse(IncidentEvidence|CoverageReportAttachment $file, string $name, string $prefix): BinaryFileResponse
    {
        abort_unless(ScanQuarantinedEvidence::type($file) === EvidenceType::Image, 404);
        $media = $this->servableMedia($file);
        abort_unless($media->hasGeneratedConversion($name), 404);

        $response = new BinaryFileResponse($media->getPath($name), 200, [
            ...$this->securityHeaders,
            'Content-Type' => 'image/jpeg',
        ], false);

        return $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, "{$prefix}-{$name}.jpg");
    }

    /** Video and audio inline, with HTTP range support. */
    private function streamResponse(IncidentEvidence|CoverageReportAttachment $file, string $prefix): BinaryFileResponse
    {
        $media = $this->servableMedia($file);
        abort_unless(is_file($media->getPath()), 404);

        $response = new BinaryFileResponse($media->getPath(), 200, [
            ...$this->securityHeaders,
            'Content-Type' => $file->mime_type,
        ], false, ResponseHeaderBag::DISPOSITION_INLINE);

        return $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $this->generatedFilename($file, $media, $prefix));
    }

    /** The original, unaltered, as an attachment (verify it first). */
    private function downloadResponse(IncidentEvidence|CoverageReportAttachment $file, Media $media, string $prefix): BinaryFileResponse
    {
        $response = new BinaryFileResponse($media->getPath(), 200, [
            ...$this->securityHeaders,
            'Content-Type' => 'application/octet-stream',
        ], false);

        return $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->generatedFilename($file, $media, $prefix));
    }

    private function servableMedia(IncidentEvidence|CoverageReportAttachment $file): Media
    {
        abort_unless($file->isScannedClean(), 404);
        $media = $file->original();
        abort_if($media === null, 404);

        return $media;
    }

    /** A generated name: never the uploader's file name. */
    private function generatedFilename(IncidentEvidence|CoverageReportAttachment $file, Media $media, string $prefix): string
    {
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION)));

        return $prefix.'-'.strtolower($file->ulid).($extension === '' ? '' : '.'.$extension);
    }
}
