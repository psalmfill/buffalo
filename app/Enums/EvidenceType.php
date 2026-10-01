<?php

namespace App\Enums;

/**
 * The kind of an evidence file (Phase 5B), decided from its content.
 */
enum EvidenceType: string
{
    case Image = 'image';
    case Document = 'document';
    case Video = 'video';
    case Audio = 'audio';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Document => 'Document',
            self::Video => 'Video',
            self::Audio => 'Audio',
        };
    }

    /** The largest accepted file, in bytes (config buffalo.evidence.max_mb). */
    public function maxBytes(): int
    {
        return (int) config("buffalo.evidence.max_mb.{$this->value}") * 1024 * 1024;
    }
}
