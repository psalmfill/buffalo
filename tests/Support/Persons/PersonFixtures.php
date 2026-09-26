<?php

namespace Tests\Support\Persons;

use App\Models\Lga;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Http\UploadedFile;

/**
 * Shared helpers for the persons tests (Phase 3A).
 */
final class PersonFixtures
{
    /** A NIN, phone, DOB and address that must never appear in clear. */
    public const SECRETS = [
        'nin' => '41234567890',
        'phone' => '0803 555 0199',
        'phone_normalised' => '+2348035550199',
        'date_of_birth' => '1979-11-02',
        'address' => '7 Lighthouse Close, Apapa',
    ];

    public static function system(): void
    {
        app(JurisdictionContext::class)->system();
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(Lga $lga, array $overrides = []): array
    {
        return [
            'lga' => $lga->ulid,
            'community' => null,
            'first_name' => 'Babatunde',
            'last_name' => 'Adeyemi',
            'other_names' => 'Olusegun',
            'gender' => 'male',
            'date_of_birth' => self::SECRETS['date_of_birth'],
            'approximate_age' => null,
            'nin' => self::SECRETS['nin'],
            'phone' => self::SECRETS['phone'],
            'address' => self::SECRETS['address'],
            'occupation' => 'Boat operator',
            'nationality' => 'Nigerian',
            'description' => 'Scar above the left eye.',
            'classification' => 'restricted',
            'is_deceased' => false,
            'roles' => [['role' => 'community_leader', 'notes' => 'Youth leader']],
            'confirm_new' => false,
            ...$overrides,
        ];
    }

    /**
     * A real JPEG with an EXIF block carrying a camera make and a GPS
     * latitude reference, both marked with $marker.
     */
    public static function jpegWithExif(string $marker = 'BUFFALOGPSMARKER', int $width = 64, int $height = 48): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 200, 60, 60));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
        file_put_contents($path, self::insertExif($jpeg, $marker));

        return new UploadedFile($path, 'IMG_0001 Adeyemi at home.jpg', 'image/jpeg', null, true);
    }

    public static function png(int $width = 40, int $height = 40): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        $path = tempnam(sys_get_temp_dir(), 'png').'.png';
        imagepng($image, $path);

        return new UploadedFile($path, 'photo.png', 'image/png', null, true);
    }

    /** A file named .jpg whose content is not an image. */
    public static function fakeJpeg(string $content = "<?php echo 'x';"): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fake').'.jpg';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    /**
     * Inserts an APP1 EXIF segment (little-endian TIFF) after SOI: IFD0
     * with Make and a GPS IFD pointer; the GPS IFD holds GPSLatitudeRef
     * and a GPSVersionID.
     */
    private static function insertExif(string $jpeg, string $marker): string
    {
        $make = $marker."\0";
        // IFD0: 2 entries (Make, GPSInfo); GPS IFD: 2 entries.
        $ifd0Offset = 8;
        $ifd0Size = 2 + 2 * 12 + 4;
        $gpsOffset = $ifd0Offset + $ifd0Size;
        $gpsSize = 2 + 2 * 12 + 4;
        $makeOffset = $gpsOffset + $gpsSize;

        $tiff = 'II'.pack('v', 42).pack('V', $ifd0Offset);
        $tiff .= pack('v', 2);
        $tiff .= pack('vvVV', 0x010F, 2, strlen($make), $makeOffset);   // Make (ASCII)
        $tiff .= pack('vvVV', 0x8825, 4, 1, $gpsOffset);                 // GPSInfo IFD pointer
        $tiff .= pack('V', 0);
        $tiff .= pack('v', 2);
        $tiff .= pack('vvV', 0x0000, 1, 4).pack('C4', 2, 3, 0, 0);       // GPSVersionID
        $tiff .= pack('vvV', 0x0001, 2, 2).'N'."\0\0\0";                 // GPSLatitudeRef
        $tiff .= pack('V', 0);
        $tiff .= $make;

        $payload = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }
}
