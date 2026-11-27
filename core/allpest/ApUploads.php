<?php
declare(strict_types=1);

require_once __DIR__ . '/ApId.php';

class ApUploadRejected extends RuntimeException
{
}

/**
 * Compressed, content-checked uploads. Images become JPEG (long edge <= 1600 px, quality 75, EXIF stripped after
 * orientation is applied); PDFs are stored as-is. Files live in the protected uploads folder under random names
 * and are only ever served through an authenticated endpoint.
 */
final class ApUploads
{
    public const MAX_IMAGE_BYTES = 15 * 1024 * 1024;
    public const MAX_PDF_BYTES = 5 * 1024 * 1024;
    public const LONG_EDGE = 1600;
    public const QUALITY = 75;

    private static ?object $db = null;
    private static ?string $dir = null;

    /** Override the database and storage directory (tests; also lets callers pass an already-resolved NuDatabase). */
    public static function configure(?object $db, ?string $dir): void
    {
        self::$db = $db;
        self::$dir = $dir !== null ? rtrim($dir, '/') : null;
    }

    /**
     * @param array{name?:string,tmp_name:string,error?:int} $phpFile an entry of $_FILES
     * @return array{file_id:string,bytes:int,sha256:string}
     */
    public static function store(array $phpFile, string $purpose, string $userOrIp, string $device): array
    {
        if ((int)($phpFile['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw new ApUploadRejected('Upload failed (code ' . (int)$phpFile['error'] . ').');
        }
        $tmp = (string)($phpFile['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new ApUploadRejected('No file received.');
        }
        $size = (int)filesize($tmp);
        $kind = self::sniff($tmp);
        if ($kind === null) {
            throw new ApUploadRejected('Only JPEG, PNG, WebP or PDF files are accepted.');
        }
        $limit = $kind === 'pdf' ? self::MAX_PDF_BYTES : self::MAX_IMAGE_BYTES;
        if ($size > $limit) {
            throw new ApUploadRejected(sprintf('File is too large (limit %d MB).', (int)($limit / 1048576)));
        }
        $raw = (string)file_get_contents($tmp);
        if ($kind === 'pdf') {
            $bytes = $raw;
            $ext = 'pdf';
            $mime = 'application/pdf';
        } else {
            $bytes = self::compressImage($raw, $kind);
            $ext = 'jpg';
            $mime = 'image/jpeg';
        }

        return self::persist($bytes, $ext, $mime, (string)($phpFile['name'] ?? ''), $purpose, $userOrIp, $device);
    }

    /**
     * Store a drawn signature (PNG with transparency) as a small PNG: scaled to at most 600 px on the long edge, maximum compression.
     * @return array{file_id:string,bytes:int,sha256:string}
     */
    public static function storePng(string $pngBytes, string $purpose, string $userOrIp, string $device): array
    {
        if (strncmp($pngBytes, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            throw new ApUploadRejected('Not a PNG image.');
        }
        $im = @imagecreatefromstring($pngBytes);
        if ($im === false) {
            throw new ApUploadRejected('The image could not be read.');
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if (max($w, $h) > 600) {
            $scale = 600 / max($w, $h);
            $scaled = imagescale($im, max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)));
            if ($scaled !== false) { $im = $scaled; }
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        ob_start();
        imagepng($im, null, 9);
        $bytes = (string)ob_get_clean();
        return self::persist($bytes, 'png', 'image/png', 'signature.png', $purpose, $userOrIp, $device);
    }

    /** @return array{file_id:string,bytes:int,sha256:string} */
    private static function persist(string $bytes, string $ext, string $mime, string $originalName, string $purpose, string $userOrIp, string $device): array
    {
        $dir = self::directory();
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $stored, $bytes) === false) {
            throw new RuntimeException('Could not write the upload.');
        }
        $id = ApId::uuid();
        $sha = hash('sha256', $bytes);
        self::db()->insert('ap_files', [
            'id' => $id,
            'purpose' => $purpose,
            'original_name' => mb_substr(basename($originalName), 0, 255),
            'stored_name' => $stored,
            'mime' => $mime,
            'bytes' => strlen($bytes),
            'sha256' => $sha,
            'uploader' => mb_substr($userOrIp, 0, 64),
            'device' => mb_substr($device, 0, 255),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return ['file_id' => $id, 'bytes' => strlen($bytes), 'sha256' => $sha];
    }

    /** Absolute path of a stored file, or null when unknown. */
    public static function path(string $fileId): ?string
    {
        $row = self::db()->fetchOne('SELECT stored_name FROM ap_files WHERE id = ?', [$fileId]);
        return $row ? self::directory() . '/' . $row['stored_name'] : null;
    }

    private static function sniff(string $path): ?string
    {
        $h = (string)file_get_contents($path, false, null, 0, 16);
        if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) { return 'jpeg'; }
        if (strncmp($h, "\x89PNG\r\n\x1a\n", 8) === 0) { return 'png'; }
        if (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP') { return 'webp'; }
        if (strncmp($h, '%PDF-', 5) === 0) { return 'pdf'; }
        return null;
    }

    private static function compressImage(string $raw, string $kind): string
    {
        $im = @imagecreatefromstring($raw);
        if ($im === false) {
            throw new ApUploadRejected('The image could not be read.');
        }
        if ($kind === 'jpeg') {
            $im = self::applyOrientation($im, $raw);
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if (max($w, $h) > self::LONG_EDGE) {
            $scale = self::LONG_EDGE / max($w, $h);
            $scaled = imagescale($im, max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)));
            if ($scaled !== false) {
                $im = $scaled;
                $w = imagesx($im);
                $h = imagesy($im);
            }
        }
        // Flatten onto white so transparency does not turn black in JPEG.
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        return (string)ob_get_clean();
    }

    /** @param \GdImage $im */
    private static function applyOrientation($im, string $raw)
    {
        if (!function_exists('exif_read_data')) {
            return $im;
        }
        try {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($raw));
        } catch (Throwable $e) {
            return $im; // malformed EXIF must never block an upload
        }
        $o = is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
        switch ($o) {
            case 2: imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 3: $im = imagerotate($im, 180, 0); break;
            case 4: imageflip($im, IMG_FLIP_VERTICAL); break;
            case 5: $im = imagerotate($im, -90, 0); imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 6: $im = imagerotate($im, -90, 0); break;
            case 7: $im = imagerotate($im, 90, 0); imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 8: $im = imagerotate($im, 90, 0); break;
        }
        return $im;
    }

    private static function db(): object
    {
        if (self::$db === null) {
            self::$db = NuDatabase::getInstance();
        }
        return self::$db;
    }

    private static function directory(): string
    {
        if (self::$dir === null) {
            global $nuConfig;
            self::$dir = rtrim((string)($nuConfig['uploadPath'] ?? sys_get_temp_dir()), '/') . '/allpest';
        }
        if (!is_dir(self::$dir) && !@mkdir(self::$dir, 0775, true) && !is_dir(self::$dir)) {
            throw new RuntimeException('Upload directory is not writable.');
        }
        if (class_exists('NuFileStore', false) || is_file(dirname(__DIR__) . '/FileStore.php')) {
            if (!class_exists('NuFileStore', false)) {
                require_once dirname(__DIR__) . '/FileStore.php';
            }
            NuFileStore::protectDir(self::$dir);
        }
        return self::$dir;
    }
}
