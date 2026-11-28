<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Read what a generated PDF carries, for assertions.
 *
 * Crude on purpose — a test helper, not a PDF reader. The documents this
 * project produces with FPDF/FPDI write drawn text as `(...) Tj` inside a
 * Flate-compressed content stream, so "does this value appear on a page" needs
 * every stream inflated first.
 *
 * ## Why this is one class and not a regex in each test
 *
 * It was a regex in each test, and the regex was subtly wrong:
 *
 *     ~stream\r?\n(.*?)\r?\nendstream~s
 *
 * The `\r?` before `\nendstream` is meant to tolerate a writer that ends its
 * stream data with CRLF. But compressed data is arbitrary bytes, and a zlib
 * stream ends with an Adler-32 checksum whose last byte is `0x0D` about one
 * time in 256. When it is, the pattern takes that byte for part of the
 * delimiter, the stream arrives one byte short, `gzuncompress()` returns false,
 * and the drawn text silently is not in the result. Measured over 3000 fills of
 * the club fixture: 10 lost their text, every one of them a stream ending in
 * a carriage return.
 *
 * To a test that reads as "the IBAN hint is not on the page" — a failure about
 * the product, intermittent, and unreproducible on the next run.
 *
 * So the delimiter here is exactly `\nendstream`, and a trailing CR is only
 * given up when the data does not inflate with it.
 */
final class PdfText
{
    /**
     * Every stream in the document, inflated where it is compressed and left
     * as it is where it is not.
     *
     * @return list<string>
     */
    public static function streams(string $pdf): array
    {
        $streams = [];

        if (preg_match_all('~stream\r?\n(.*?)\nendstream~s', $pdf, $matches)) {
            foreach ($matches[1] as $raw) {
                $streams[] = self::inflate($raw) ?? self::withoutTrailingCr($raw);
            }
        }

        return $streams;
    }

    /**
     * The whole document as text: its raw bytes, followed by everything its
     * compressed streams inflate to.
     */
    public static function of(string $pdf): string
    {
        $text = $pdf;

        if (preg_match_all('~stream\r?\n(.*?)\nendstream~s', $pdf, $matches)) {
            foreach ($matches[1] as $raw) {
                $text .= self::inflate($raw) ?? '';
            }
        }

        return $text;
    }

    /**
     * The inflated stream, or null when it is not Flate data.
     *
     * Tried as matched first — which is right for every writer that ends its
     * data with a bare LF, FPDF included — and only then without a trailing
     * CR, for one that writes CRLF before `endstream`.
     */
    private static function inflate(string $raw): ?string
    {
        $inflated = @gzuncompress($raw);
        if ($inflated !== false) {
            return $inflated;
        }

        if (str_ends_with($raw, "\r")) {
            $inflated = @gzuncompress(substr($raw, 0, -1));
            if ($inflated !== false) {
                return $inflated;
            }
        }

        return null;
    }

    private static function withoutTrailingCr(string $raw): string
    {
        return str_ends_with($raw, "\r") ? substr($raw, 0, -1) : $raw;
    }
}
