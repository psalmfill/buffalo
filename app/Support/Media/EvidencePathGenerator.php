<?php

namespace App\Support\Media;

use App\Models\Media as BuffaloMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Stores each file under `{collection}/{media ulid}/` on the evidence disk
 * (conversions in a `conversions/` subdirectory), so directory names never
 * reveal numeric ids or record counts.
 */
final class EvidencePathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->basePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->basePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->basePath($media).'/responsive-images/';
    }

    private function basePath(Media $media): string
    {
        $ulid = $media instanceof BuffaloMedia ? $media->ulid : null;

        if (! is_string($ulid) || $ulid === '') {
            throw new \LogicException('Media must use App\Models\Media, which has a ULID.');
        }

        return preg_replace('/[^a-z0-9_-]/', '', $media->collection_name).'/'.strtolower($ulid);
    }
}
