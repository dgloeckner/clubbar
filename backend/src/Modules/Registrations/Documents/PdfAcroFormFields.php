<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Documents;

/**
 * The names and rectangles of a template's AcroForm text fields (#780,
 * ADR-0052 decision 5).
 *
 * ## The fields are an address book, not a form
 *
 * Nothing here fills a form, and the output — `name => [x1, y1, x2, y2]` — is
 * the whole of what the filler needs: where on page 1 to draw each value. The
 * fields themselves never survive into the output, because FPDI does not import
 * annotations, which is what makes the result flattened by construction rather
 * than by a flattening step that could be forgotten.
 *
 * ## Why this reads the raw bytes instead of asking a library
 *
 * Two reasons, and the second is the one that matters. The first is that no
 * free PHP library exposes widget rectangles: FPDI parses pages, not forms, and
 * the maintained form fillers are commercial. The second is that this class has
 * to be able to describe a template it *cannot* read — a compressed one, a
 * cross-reference stream, an HTML error page a webhost served with a 200 — and
 * a parser that throws on those tells the club "invalid PDF" when the actual
 * answer is a build flag. {@see diagnose()} exists for exactly that.
 *
 * ## The corner-order bug, which is invisible when it happens
 *
 * PDF permits `/Rect` to name any two opposite corners, and WeasyPrint writes
 * them top-down. Read literally the height is negative, every value lands off
 * the page, and the resulting document looks precisely like a fill that quietly
 * did nothing. Normalizing here is why that cannot recur.
 */
final class PdfAcroFormFields
{
    /**
     * Every text-field widget in the file, in the order the file declares them.
     *
     * @return array<string, array{0: float, 1: float, 2: float, 3: float}>
     *         field name => [x1, y1, x2, y2], corners normalized so x1 < x2 and y1 < y2
     */
    public static function scan(string $raw): array
    {
        $fields = [];

        if (!preg_match_all('~\d+\s+\d+\s+obj(.*?)endobj~s', $raw, $objects)) {
            return [];
        }

        foreach ($objects[1] as $object) {
            // `/Widget` and not merely `/T`: an outline entry carries a title
            // too, and treating one as a field would draw a member's name at a
            // rectangle nobody put there.
            if (!str_contains($object, '/Widget')) {
                continue;
            }
            if (!preg_match('~/T\s*\(([^)]*)\)~', $object, $name)) {
                continue;
            }
            if (!preg_match('~/Rect\s*\[\s*([0-9.eE+\s-]+)\]~', $object, $rect)) {
                continue;
            }

            $corners = array_map('floatval', preg_split('~\s+~', trim($rect[1])) ?: []);
            if (count($corners) !== 4) {
                continue;
            }

            $fields[$name[1]] = [
                min($corners[0], $corners[2]),
                min($corners[1], $corners[3]),
                max($corners[0], $corners[2]),
                max($corners[1], $corners[3]),
            ];
        }

        return $fields;
    }

    /**
     * Why {@see scan()} found nothing — or null if it found something.
     *
     * Ordered by how badly the alternatives mislead. "Not a PDF" comes first
     * because a webhost's HTML 404 is the likeliest real failure and the one
     * where PDF-shaped advice wastes the most time; the two build-flag cases
     * come next, because their field names are usually correct and the club
     * would otherwise be sent to audit them.
     */
    public static function diagnose(string $raw): ?TemplateProblem
    {
        if (self::scan($raw) !== []) {
            return null;
        }

        if (!str_starts_with(ltrim($raw), '%PDF-')) {
            return TemplateProblem::NOT_A_PDF;
        }

        if (str_contains($raw, '/ObjStm')) {
            return TemplateProblem::COMPRESSED_OBJECT_STREAMS;
        }

        // A classic table is the literal keyword `xref` on its own; a
        // cross-reference *stream* declares `/Type /XRef` instead, and the free
        // FPDI parser cannot follow one.
        if (str_contains($raw, '/XRef') || !preg_match('~(^|[\r\n])xref[\r\n]~', $raw)) {
            return TemplateProblem::NO_CLASSIC_XREF;
        }

        return TemplateProblem::NO_FORM_FIELDS;
    }

    /**
     * The field names a template must carry to be usable at all.
     *
     * Member-specific data only. A club's own template prints its creditor
     * block statically — its identity belongs in its own document — and
     * Ort/Datum, the signatures and the Kenntnisnahme boxes are done by hand at
     * signature and are not fields in a valid template.
     *
     * @return list<string>
     */
    public static function requiredFields(): array
    {
        return ['mandatsreferenz', 'vorname', 'nachname', 'iban', 'iban_last4'];
    }

    /**
     * Which required fields a template is missing.
     *
     * `iban` is satisfied **either** by a single wide field **or** by an
     * IBAN-Kamm: a template that prints one box per character has no field
     * called `iban` at all, and demanding one would refuse the shape a German
     * form actually uses (#780).
     *
     * @param array<string, mixed> $fields as returned by {@see scan()}
     * @return list<string>
     */
    public static function missingRequired(array $fields): array
    {
        $present = array_keys($fields);

        if (self::combCells($fields) !== []) {
            $present[] = 'iban';
        }

        return array_values(array_diff(self::requiredFields(), $present));
    }

    /**
     * The IBAN comb's cells, as `index => field name`, lowest index first.
     *
     * The index is the **character position** in the IBAN, not the cell's
     * ordinal among the fields present — so a form that pre-prints `DE` into
     * the first two boxes declares `iban_3` … `iban_22` and every character
     * still lands where it belongs.
     *
     * @param array<string, mixed> $fields
     * @return array<int, string>
     */
    public static function combCells(array $fields): array
    {
        $cells = [];

        foreach (array_keys($fields) as $name) {
            if (preg_match('/^iban_([1-9]\d*)$/', (string) $name, $parts) === 1) {
                $cells[(int) $parts[1]] = (string) $name;
            }
        }

        ksort($cells);

        return $cells;
    }
}
