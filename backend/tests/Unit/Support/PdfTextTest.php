<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\PdfText;

/**
 * The helper every PDF assertion reads through — and the regression test for
 * the flake it replaced.
 *
 * Each document test used to carry its own
 * `~stream\r?\n(.*?)\r?\nendstream~s`. The `\r?` before the delimiter swallows
 * the last byte of a compressed stream whenever that byte happens to be a
 * carriage return, which for zlib data is about one stream in 256. The stream
 * then fails to inflate and the text it drew is simply absent — so a test
 * asserting "the IBAN hint is on the page" failed roughly once in three
 * hundred runs, with a message about the product.
 */
final class PdfTextTest extends TestCase
{
    /**
     * A payload whose compressed form ends in a carriage return.
     *
     * Found by search rather than written down: which input produces such a
     * stream depends on the zlib build, and a hard-coded one would quietly
     * stop exercising the case on the next library update.
     *
     * @return array{0: string, 1: string} the plaintext and its compressed form
     */
    private function streamEndingInCarriageReturn(): array
    {
        for ($i = 0; $i < 20000; $i++) {
            $plain = "BT (endet auf ****3000) Tj ET % {$i}";
            $compressed = (string) gzcompress($plain);

            if (str_ends_with($compressed, "\r")) {
                return [$plain, $compressed];
            }
        }

        self::fail('No payload compressing to a stream that ends in CR was found in 20000 tries.');
    }

    private function pdfAround(string $streamData, string $beforeEndstream = "\n"): string
    {
        return "%PDF-1.7\n4 0 obj\n<< /Filter /FlateDecode /Length " . strlen($streamData) . " >>\n"
            . "stream\n" . $streamData . $beforeEndstream . "endstream\nendobj\n%%EOF\n";
    }

    /** The case that used to lose the text: FPDF's bare LF, data ending in CR. */
    public function test_a_stream_whose_compressed_data_ends_in_a_carriage_return_is_still_read(): void
    {
        [$plain, $compressed] = $this->streamEndingInCarriageReturn();
        $pdf = $this->pdfAround($compressed);

        // The old pattern, for the record of what it did with this document.
        preg_match('~stream\r?\n(.*?)\r?\nendstream~s', $pdf, $old);
        self::assertFalse(@gzuncompress($old[1]), 'The per-test regex truncated this stream.');

        self::assertStringContainsString($plain, PdfText::of($pdf));
        self::assertSame([$plain], PdfText::streams($pdf));
    }

    /** A writer that ends its stream data with CRLF must still be readable. */
    public function test_a_stream_delimited_by_crlf_is_still_read(): void
    {
        $plain = 'BT (Lena) Tj ET';
        $pdf = $this->pdfAround((string) gzcompress($plain), "\r\n");

        self::assertStringContainsString($plain, PdfText::of($pdf));
        self::assertSame([$plain], PdfText::streams($pdf));
    }

    /** Both at once: data ending in CR, and a CRLF delimiter after it. */
    public function test_a_carriage_return_in_the_data_survives_a_crlf_delimiter(): void
    {
        [$plain, $compressed] = $this->streamEndingInCarriageReturn();

        self::assertStringContainsString($plain, PdfText::of($this->pdfAround($compressed, "\r\n")));
    }

    public function test_an_uncompressed_stream_is_returned_as_it_is(): void
    {
        $pdf = $this->pdfAround('BT (plain) Tj ET');

        self::assertSame(['BT (plain) Tj ET'], PdfText::streams($pdf));
        // Its bytes are in the document already, so the text finds them there.
        self::assertStringContainsString('(plain) Tj', PdfText::of($pdf));
    }

    public function test_every_stream_of_a_document_is_read(): void
    {
        $pdf = $this->pdfAround((string) gzcompress('BT (one) Tj ET'))
            . $this->pdfAround((string) gzcompress('BT (two) Tj ET'));

        self::assertSame(['BT (one) Tj ET', 'BT (two) Tj ET'], PdfText::streams($pdf));
    }
}
