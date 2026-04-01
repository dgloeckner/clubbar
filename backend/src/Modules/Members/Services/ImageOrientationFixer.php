<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

/**
 * Auto-rotates image bytes based on the EXIF Orientation tag.
 *
 * Phone cameras (iPhone, Android) store images in landscape pixel order but tag
 * them with an EXIF Orientation indicating the desired display rotation. APIs
 * that ignore EXIF metadata (Google Cloud Vision, OpenAI Vision) then see the
 * image sideways, causing OCR to fail or return garbled text.
 *
 * This class corrects the rotation in-memory before any outbound API call.
 * If the EXIF extension is unavailable or the image cannot be decoded, the
 * original bytes are returned unchanged.
 *
 * Supported EXIF orientations:
 *   1 = Normal (no rotation)
 *   3 = Rotate 180°
 *   6 = Rotate 90° CW
 *   8 = Rotate 90° CCW
 */
class ImageOrientationFixer
{
    /**
     * @param  string $bytes  Raw image bytes (JPEG or PNG).
     * @return string         Bytes with rotation applied, or original if unchanged.
     */
    public static function fix(string $bytes): string
    {
        if (!function_exists('exif_read_data')) {
            return $bytes;
        }

        // exif_read_data needs a file path; use a temp file.
        $tmp = tempnam(sys_get_temp_dir(), 'exif_');
        if ($tmp === false) {
            return $bytes;
        }

        try {
            file_put_contents($tmp, $bytes);
            $exif        = @exif_read_data($tmp);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        } finally {
            unlink($tmp);
        }

        // GD imagerotate angle: positive = counter-clockwise, negative = clockwise.
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,  // 90° CW display correction: rotate -90° (= 90° CW in GD terms)
            8 =>  90,  // 90° CCW display correction: rotate +90° (= 90° CCW in GD terms)
            default => 0,
        };

        if ($angle === 0) {
            return $bytes;
        }

        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            return $bytes;
        }

        $white   = imagecolorallocate($img, 255, 255, 255) ?: 0;
        $rotated = imagerotate($img, $angle, $white);
        imagedestroy($img);

        if (!$rotated) {
            return $bytes;
        }

        ob_start();
        imagejpeg($rotated, null, 92);
        $result = ob_get_clean();
        imagedestroy($rotated);

        return ($result !== false && $result !== '') ? $result : $bytes;
    }
}
