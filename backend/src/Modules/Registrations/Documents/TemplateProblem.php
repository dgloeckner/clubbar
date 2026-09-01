<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Documents;

use App\Shared\Exceptions\BusinessRuleReason;

/**
 * Why a club's document cannot be used as a template (#780).
 *
 * Four ways to end up with no fields, and the club needs to be told which —
 * because three of them have completely different fixes and only one of them
 * is "check your field names". A bare "no fields found" sends somebody to
 * audit a template whose names are perfectly correct, sitting in a compressed
 * blob nothing can read.
 */
enum TemplateProblem: string
{
    /**
     * The build wrote object streams, so the field definitions are inside a
     * compressed blob. The names are almost certainly right; the flag is wrong.
     */
    case COMPRESSED_OBJECT_STREAMS = 'compressed_object_streams';

    /**
     * A cross-reference *stream* rather than a classic table. The free FPDI
     * parser cannot follow one, so even a template this scanner could read
     * would fail at the import step. Same fix as above.
     */
    case NO_CLASSIC_XREF = 'no_classic_xref';

    /**
     * A readable PDF that simply has no form fields. Usually a document
     * exported by the club's ordinary print path — Chromium renders the same
     * HTML with zero AcroForm fields, which is exactly why WeasyPrint is not
     * optional here.
     */
    case NO_FORM_FIELDS = 'no_form_fields';

    /**
     * Not a PDF. The likeliest real cause is a club webhost answering a moved
     * or missing file with an HTML error page and a 200 status, which no
     * amount of PDF diagnosis will explain.
     */
    case NOT_A_PDF = 'not_a_pdf';

    /**
     * The refusal an admin sees, in their own language.
     *
     * The first three share a code because they share a *fix* — rebuild with
     * `--pdf-forms --uncompressed-pdf` — and a refusal that split hairs the
     * reader cannot act on differently is a worse refusal. The parameter
     * carries which of them it was, so the sentence can still be specific.
     */
    public function reason(): BusinessRuleReason
    {
        return match ($this) {
            self::NOT_A_PDF => BusinessRuleReason::DOCUMENT_TEMPLATE_NOT_A_PDF,
            default => BusinessRuleReason::DOCUMENT_TEMPLATE_UNREADABLE,
        };
    }
}
