<?php

namespace App\Support\Evidence;

use App\Enums\EvidenceType;
use finfo;

/**
 * Decides what an uploaded evidence file is (Phase 5B) from its content
 * (libmagic), never from its name or the browser's claim. The file's
 * extension must agree with its content; anything not listed is refused
 * (executables, scripts, archives, HTML, SVG...).
 *
 * HEIC is not accepted: the PHP image stack (GD) cannot decode it, so no
 * preview could be made.
 */
final class EvidenceFileInspector
{
    /**
     * Detected content type => [evidence type, accepted extensions].
     *
     * @var array<string, array{0: EvidenceType, 1: list<string>}>
     */
    private const ACCEPTED = [
        'image/jpeg' => [EvidenceType::Image, ['jpg', 'jpeg']],
        'image/png' => [EvidenceType::Image, ['png']],
        'image/webp' => [EvidenceType::Image, ['webp']],
        'application/pdf' => [EvidenceType::Document, ['pdf']],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => [EvidenceType::Document, ['docx']],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => [EvidenceType::Document, ['xlsx']],
        'text/plain' => [EvidenceType::Document, ['txt']],
        'video/mp4' => [EvidenceType::Video, ['mp4', 'm4v']],
        'video/webm' => [EvidenceType::Video, ['webm']],
        'audio/mpeg' => [EvidenceType::Audio, ['mp3']],
        'audio/x-m4a' => [EvidenceType::Audio, ['m4a']],
        'audio/mp4' => [EvidenceType::Audio, ['m4a']],
        'audio/ogg' => [EvidenceType::Audio, ['ogg', 'oga', 'opus']],
        'audio/x-wav' => [EvidenceType::Audio, ['wav']],
        'audio/wav' => [EvidenceType::Audio, ['wav']],
        'audio/vnd.wave' => [EvidenceType::Audio, ['wav']],
    ];

    /** For the upload form's `accept` attribute (a hint only). */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'docx', 'xlsx', 'txt', 'mp4', 'm4v', 'webm', 'mp3', 'm4a', 'ogg', 'oga', 'opus', 'wav'];

    /**
     * What the file at $path is, or why it is refused.
     *
     * @return array{type: EvidenceType, mime: string, extension: string}|string
     */
    public function inspect(string $path, string $originalName): array|string
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            return 'The file is empty.';
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $accepted = is_string($mime) ? (self::ACCEPTED[$mime] ?? null) : null;

        if ($accepted === null) {
            return 'This kind of file is not accepted. Upload images (JPEG, PNG, WebP), documents (PDF, DOCX, XLSX, text), video (MP4, WebM) or audio (MP3, M4A, OGG, WAV).';
        }

        [$type, $extensions] = $accepted;
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (! in_array($extension, $extensions, true)) {
            return "The file's name does not match its content ({$type->label()}: .".implode(', .', $extensions).').';
        }

        if ($size > $type->maxBytes()) {
            return "{$type->label()} files may be at most ".intdiv($type->maxBytes(), 1024 * 1024).' MB.';
        }

        return ['type' => $type, 'mime' => (string) $mime, 'extension' => $extension];
    }
}
