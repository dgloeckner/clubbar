<?php

declare(strict_types=1);

namespace App\Shared\Utils;

/**
 * Determines an upload's type from its bytes.
 *
 * Every upload endpoint used to validate `UploadedFileInterface::getClientMediaType()`,
 * which is the multipart `Content-Type` header — a value the client writes and
 * an attacker therefore chooses. Declaring `application/pdf` was enough to get
 * arbitrary bytes past the mandate allowlist and into storage (#107).
 *
 * finfo reads the magic bytes instead, so the answer is a property of the file.
 * That is not a content *safety* check — a real PDF can still be malicious — it
 * only guarantees the file is the kind of thing the allowlist admits, which is
 * what the storage and download paths downstream assume.
 */
final class MimeSniffer
{
    /**
     * The MIME type of the given bytes, or an empty string when finfo cannot
     * tell. An empty string never matches an allowlist, so an undetectable file
     * is rejected rather than waved through.
     */
    public static function detect(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return $detected === false ? '' : $detected;
    }
}
