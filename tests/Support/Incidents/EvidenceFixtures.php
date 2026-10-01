<?php

namespace Tests\Support\Incidents;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Evidence files for tests (Phase 5B), built in memory: real magic bytes for
 * every accepted format, a JPEG with camera, time and GPS EXIF, and files
 * that must be refused.
 */
final class EvidenceFixtures
{
    public const MARKER = 'BUFFALOEXIFMARKER';

    /**
     * A JPEG with EXIF: Make (carrying MARKER), Model, DateTimeOriginal and
     * a GPS position (degrees, minutes, seconds rationals).
     */
    public static function photo(
        float $latitude = 6.455,
        float $longitude = 3.3841,
        string $takenAt = '2026:09:30 14:05:10',
        string $name = 'IMG_2044.jpg',
        int $width = 640,
        int $height = 480,
    ): UploadedFile {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 40, 90, 160));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        $ascii = fn (string $s): string => $s."\0";
        $rationals = function (float $value): string {
            $value = abs($value);
            $degrees = (int) floor($value);
            $minutes = (int) floor(($value - $degrees) * 60);
            $seconds = (int) round((($value - $degrees) * 60 - $minutes) * 60 * 100);

            return pack('VV', $degrees, 1).pack('VV', $minutes, 1).pack('VV', $seconds, 100);
        };

        $exif = [[0x9003, 2, 20, $ascii($takenAt)]];
        $gps = [
            [0x0001, 2, 2, $latitude < 0 ? "S\0" : "N\0"],
            [0x0002, 5, 3, $rationals($latitude)],
            [0x0003, 2, 2, $longitude < 0 ? "W\0" : "E\0"],
            [0x0004, 5, 3, $rationals($longitude)],
        ];
        $make = $ascii(self::MARKER);
        $model = $ascii('Pixel 8');

        // Layout: header (8) | IFD0 | EXIF IFD | GPS IFD.
        $ifd0Size = strlen(self::ifd([[0x010F, 2, strlen($make), $make], [0x0110, 2, strlen($model), $model], [0x8769, 4, 1, pack('V', 0)], [0x8825, 4, 1, pack('V', 0)]], 0));
        $exifStart = 8 + $ifd0Size;
        $gpsStart = $exifStart + strlen(self::ifd($exif, 0));

        $ifd0 = self::ifd([
            [0x010F, 2, strlen($make), $make],
            [0x0110, 2, strlen($model), $model],
            [0x8769, 4, 1, pack('V', $exifStart)],
            [0x8825, 4, 1, pack('V', $gpsStart)],
        ], 8);
        $tiff = 'II'.pack('v', 42).pack('V', 8).$ifd0.self::ifd($exif, $exifStart).self::ifd($gps, $gpsStart);

        $payload = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return self::file($name, substr($jpeg, 0, 2).$segment.substr($jpeg, 2));
    }

    public static function png(string $name = 'scene.png'): UploadedFile
    {
        $image = imagecreatetruecolor(32, 32);
        ob_start();
        imagepng($image);

        return self::file($name, (string) ob_get_clean());
    }

    public static function pdf(string $name = 'statement.pdf'): UploadedFile
    {
        return self::file($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    public static function text(string $name = 'notes.txt'): UploadedFile
    {
        return self::file($name, "Witness statement taken at the scene.\n");
    }

    public static function docx(string $name = 'report.docx'): UploadedFile
    {
        return self::file($name, self::zip(['[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>', '_rels/.rels' => '<x/>', 'word/document.xml' => '<w:document/>']));
    }

    public static function xlsx(string $name = 'list.xlsx'): UploadedFile
    {
        return self::file($name, self::zip(['[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>', '_rels/.rels' => '<x/>', 'xl/workbook.xml' => '<workbook/>']));
    }

    /** An MP4 of about $bytes bytes (ftyp box, then padding in mdat). */
    public static function mp4(int $bytes = 4096, string $name = 'clip.mp4'): UploadedFile
    {
        $ftyp = self::box('ftyp', 'isom'.pack('N', 512).'isomiso2avc1mp41');
        $mdat = self::box('mdat', random_bytes(max(16, $bytes - strlen($ftyp) - 8)));

        return self::file($name, $ftyp.$mdat);
    }

    public static function webm(string $name = 'clip.webm'): UploadedFile
    {
        return self::file($name, (string) hex2bin('1A45DFA39F4286810142F7810142F2810442F381084282847765626D4287810442858102').str_repeat("\0", 64));
    }

    public static function mp3(string $name = 'call.mp3'): UploadedFile
    {
        return self::file($name, "ID3\x03\0\0\0\0\0\x0a".str_repeat("\0", 10)."\xFF\xFB\x90\x64".str_repeat("\0", 400));
    }

    public static function m4a(string $name = 'memo.m4a'): UploadedFile
    {
        return self::file($name, self::box('ftyp', 'M4A '.pack('N', 0).'M4A mp42isom').self::box('mdat', str_repeat("\0", 64)));
    }

    public static function ogg(string $name = 'radio.ogg'): UploadedFile
    {
        return self::file($name, "OggS\0\2".str_repeat("\0", 20)."\x01\x1e"."\x01vorbis".str_repeat("\0", 60));
    }

    public static function wav(string $name = 'recording.wav'): UploadedFile
    {
        return self::file($name, 'RIFF'.pack('V', 36).'WAVEfmt '.pack('V', 16).pack('vvVVvv', 1, 1, 8000, 16000, 2, 16).'data'.pack('V', 0));
    }

    /** A Windows executable renamed to look like a photo. */
    public static function renamedExecutable(string $name = 'photo.jpg'): UploadedFile
    {
        return self::file($name, 'MZ'.str_repeat("\0", 58).pack('V', 64)."PE\0\0".str_repeat("\0", 200));
    }

    public static function renamedScript(string $name = 'statement.txt'): UploadedFile
    {
        return self::file($name, "<?php system(\$_GET['c']);\n");
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: string}>  $entries  tag, type, count, raw value
     */
    private static function ifd(array $entries, int $start): string
    {
        $dataOffset = $start + 2 + 12 * count($entries) + 4;
        $head = pack('v', count($entries));
        $data = '';

        foreach ($entries as [$tag, $type, $count, $bytes]) {
            if (strlen($bytes) <= 4) {
                $head .= pack('vvV', $tag, $type, $count).str_pad($bytes, 4, "\0");
            } else {
                $head .= pack('vvVV', $tag, $type, $count, $dataOffset + strlen($data));
                $data .= $bytes;

                if (strlen($data) % 2 === 1) {
                    $data .= "\0";
                }
            }
        }

        return $head.pack('V', 0).$data;
    }

    private static function box(string $type, string $body): string
    {
        return pack('N', 8 + strlen($body)).$type.$body;
    }

    /**
     * @param  array<string, string>  $entries
     */
    private static function zip(array $entries): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return (string) file_get_contents($path);
    }

    private static function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence');
        file_put_contents((string) $path, $content);

        return new UploadedFile((string) $path, $name, null, null, true);
    }
}
