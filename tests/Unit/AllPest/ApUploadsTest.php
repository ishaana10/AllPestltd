<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
require_once dirname(__DIR__, 3) . '/core/allpest/ApId.php';
require_once dirname(__DIR__, 3) . '/core/allpest/ApSchema.php';
require_once dirname(__DIR__, 3) . '/core/allpest/ApUploads.php';

final class ApUploadsTest extends TestCase
{
    private ApTestDb $db;
    private string $dir;
    /** @var string[] */
    private array $tmp = [];

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        $this->dir = sys_get_temp_dir() . '/ap_up_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        ApUploads::configure($this->db, $this->dir);
    }

    protected function tearDown(): void
    {
        foreach (array_merge($this->tmp, glob($this->dir . '/{,.}*', GLOB_BRACE) ?: []) as $f) {
            if (is_file($f)) { @unlink($f); }
        }
        @rmdir($this->dir);
    }

    private function phpFile(string $bytes, string $name): array
    {
        $p = tempnam(sys_get_temp_dir(), 'apt');
        file_put_contents($p, $bytes);
        $this->tmp[] = $p;
        return ['name' => $name, 'type' => 'x', 'size' => strlen($bytes), 'tmp_name' => $p, 'error' => 0];
    }

    private function jpeg(int $w, int $h, string $injectApp1 = ''): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 200; $i++) {
            imagefilledrectangle($im, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, 0xFFFFFF));
        }
        ob_start(); imagejpeg($im, null, 95); $b = (string)ob_get_clean();
        if ($injectApp1 !== '') {
            $b = substr($b, 0, 2) . "\xFF\xE1" . pack('n', strlen($injectApp1) + 2) . $injectApp1 . substr($b, 2);
        }
        return $b;
    }

    private function storedPath(string $fileId): string
    {
        $row = $this->db->fetchOne('SELECT stored_name FROM ap_files WHERE id = ?', [$fileId]);
        $this->assertNotNull($row);
        return $this->dir . '/' . $row['stored_name'];
    }

    public function testLargeJpegIsScaledToLongEdge1600AndSmaller(): void
    {
        $src = $this->jpeg(4000, 3000);
        $r = ApUploads::store($this->phpFile($src, 'a.jpg'), 'reading_photo', 'tech1', 'tablet-1');
        $info = getimagesize($this->storedPath($r['file_id']));
        $this->assertSame(1600, max($info[0], $info[1]));
        $this->assertSame(1200, min($info[0], $info[1]));
        $this->assertLessThan(strlen($src), $r['bytes']);
    }

    public function testPngWithTransparencyIsStoredAsJpeg(): void
    {
        $im = imagecreatetruecolor(200, 100);
        imagealphablending($im, false); imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 0, 0, 100));
        ob_start(); imagepng($im); $png = (string)ob_get_clean();
        $r = ApUploads::store($this->phpFile($png, 'a.png'), 'bol', '1.2.3.4', 'web');
        $this->assertSame(IMAGETYPE_JPEG, getimagesize($this->storedPath($r['file_id']))[2]);
        $row = $this->db->fetchOne('SELECT mime FROM ap_files WHERE id = ?', [$r['file_id']]);
        $this->assertSame('image/jpeg', $row['mime']);
    }

    public function testHugePixelDimensionsAreRejectedBeforeDecoding(): void
    {
        // A tiny PNG whose header claims 20000 x 20000 pixels (a decompression bomb): must be refused without decoding it.
        $ihdr = pack('NN', 20000, 20000) . "\x08\x02\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
        $this->expectException(ApUploadRejected::class);
        ApUploads::store($this->phpFile($png, 'bomb.png'), 'reading_photo', 'u', 'd');
    }

    public function testTextFileRenamedJpgIsRejected(): void
    {
        $this->expectException(ApUploadRejected::class);
        ApUploads::store($this->phpFile("hello, I am not an image\n", 'evil.jpg'), 'bol', 'ip', 'web');
    }

    public function testPdfOverFiveMbIsRejected(): void
    {
        $this->expectException(ApUploadRejected::class);
        ApUploads::store($this->phpFile("%PDF-1.4\n" . str_repeat('A', 6 * 1024 * 1024), 'a.pdf'), 'bol', 'ip', 'web');
    }

    public function testSmallPdfIsStoredUnchanged(): void
    {
        $pdf = "%PDF-1.4\n" . str_repeat('A', 2000) . "\n%%EOF";
        $r = ApUploads::store($this->phpFile($pdf, 'a.pdf'), 'bol', 'ip', 'web');
        $this->assertSame($pdf, file_get_contents($this->storedPath($r['file_id'])));
    }

    public function testImageOverFifteenMbIsRejected(): void
    {
        $this->expectException(ApUploadRejected::class);
        ApUploads::store($this->phpFile("\xFF\xD8\xFF\xE0" . str_repeat('A', 16 * 1024 * 1024), 'a.jpg'), 'bol', 'ip', 'web');
    }

    public function testStoredSha256MatchesStoredBytesAndMetadataRecorded(): void
    {
        $r = ApUploads::store($this->phpFile($this->jpeg(800, 600), 'a.jpg'), 'reading_photo', 'tech1', 'tablet-1');
        $bytes = (string)file_get_contents($this->storedPath($r['file_id']));
        $this->assertSame(hash('sha256', $bytes), $r['sha256']);
        $this->assertSame(strlen($bytes), $r['bytes']);
        $row = $this->db->fetchOne('SELECT * FROM ap_files WHERE id = ?', [$r['file_id']]);
        $this->assertSame('reading_photo', $row['purpose']);
        $this->assertSame('tech1', $row['uploader']);
        $this->assertSame('tablet-1', $row['device']);
        $this->assertSame($r['sha256'], $row['sha256']);
    }

    public function testExifGpsIsStripped(): void
    {
        $exif = "Exif\0\0" . "II*\0\x08\0\0\0" . str_repeat('GPSLatitude-17.7134 GPSLongitude177.4', 3);
        $src = $this->jpeg(400, 300, $exif);
        $this->assertStringContainsString('GPSLatitude', $src);
        $r = ApUploads::store($this->phpFile($src, 'a.jpg'), 'reading_photo', 'tech1', 'tab');
        $out = (string)file_get_contents($this->storedPath($r['file_id']));
        $this->assertStringNotContainsString('GPS', $out);
        $this->assertStringNotContainsString('Exif', $out);
    }

    public function testExifOrientationIsAppliedBeforeStripping(): void
    {
        // TIFF header, one IFD entry: Orientation (0x0112) = 6 (rotate 90 CW)
        $tiff = "II*\0\x08\0\0\0" . "\x01\0" . "\x12\x01\x03\0\x01\0\0\0\x06\0\0\0" . "\0\0\0\0";
        $src = $this->jpeg(400, 200, "Exif\0\0" . $tiff);
        $r = ApUploads::store($this->phpFile($src, 'a.jpg'), 'reading_photo', 'tech1', 'tab');
        $info = getimagesize($this->storedPath($r['file_id']));
        $this->assertSame(200, $info[0]);
        $this->assertSame(400, $info[1]);
    }

    public function testFilesRowGetsUuidAndStoredNameIsRandom(): void
    {
        $r = ApUploads::store($this->phpFile($this->jpeg(100, 100), 'My Photo.jpg'), 'bol', 'ip', 'web');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $r['file_id']);
        $row = $this->db->fetchOne('SELECT stored_name, original_name FROM ap_files WHERE id = ?', [$r['file_id']]);
        $this->assertStringNotContainsString('Photo', $row['stored_name']);
        $this->assertSame('My Photo.jpg', $row['original_name']);
    }
}
