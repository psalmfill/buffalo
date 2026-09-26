<?php

namespace App\Support\Persons;

use GdImage;
use InvalidArgumentException;

/**
 * Re-encodes an uploaded photo with GD before it is stored, so no metadata
 * survives: EXIF (including GPS location), XMP, IPTC, comments, PNG text
 * chunks. Re-encoding also discards anything appended to or hidden in the
 * file. The EXIF orientation is applied first, so photos stay upright.
 *
 * The type is decided by the file's content (getimagesize), never by its
 * name or the browser's claim.
 */
final class PhotoSanitiser
{
    /** Longest side, in pixels (GD decodes the whole image in memory). */
    public const MAX_DIMENSION = 6000;

    /** @var array<int, string> image type => stored extension */
    private const TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * Why $path is not an acceptable photo, or null.
     */
    public static function problem(string $path): ?string
    {
        $info = @getimagesize($path);

        if ($info === false || ! isset(self::TYPES[$info[2]])) {
            return 'The photo must be a JPEG, PNG or WebP image.';
        }

        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            return 'The photo may be at most '.self::MAX_DIMENSION.' pixels wide and high.';
        }

        return null;
    }

    /**
     * Writes a clean copy of the image at $path to a new temporary file.
     *
     * @return array{path: string, extension: string, mime: string}
     */
    public function sanitise(string $path): array
    {
        $problem = self::problem($path);

        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        $info = getimagesize($path);

        if ($info === false) {
            throw new InvalidArgumentException('The photo could not be read.');
        }

        $type = $info[2];

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            default => @imagecreatefromwebp($path),
        };

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('The photo could not be read.');
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = self::upright($image, $path);
        }

        $target = tempnam(sys_get_temp_dir(), 'buffalo-photo-');

        if ($target === false) {
            throw new \RuntimeException('No temporary file for the photo.');
        }

        $written = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, $target, 90),
            IMAGETYPE_PNG => imagealphablending($image, false) && imagesavealpha($image, true) && imagepng($image, $target, 6),
            default => imagewebp($image, $target, 90),
        };

        imagedestroy($image);

        if (! $written) {
            @unlink($target);

            throw new \RuntimeException('The photo could not be written.');
        }

        return [
            'path' => $target,
            'extension' => self::TYPES[$type],
            'mime' => (string) image_type_to_mime_type($type),
        ];
    }

    /**
     * Applies the EXIF orientation (the tag itself is dropped on encoding).
     */
    private static function upright(GdImage $image, string $path): GdImage
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotate = fn (GdImage $img, int $angle): GdImage => imagerotate($img, $angle, 0) ?: $img;

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = $rotate($image, 180);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                imageflip($image, IMG_FLIP_VERTICAL);
                $image = $rotate($image, -90);
                break;
            case 6:
                $image = $rotate($image, -90);
                break;
            case 7:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                $image = $rotate($image, -90);
                break;
            case 8:
                $image = $rotate($image, 90);
                break;
        }

        return $image;
    }
}
