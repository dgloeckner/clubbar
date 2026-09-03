<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Documents;

use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;

/**
 * The club's document cannot be used as a mandate template (#780).
 *
 * A `BusinessRuleException`, so the panel translates it like any other refusal
 * (Pattern 019) — but carrying the structured facts a sentence needs to be
 * actionable rather than merely polite: *which* problem, *which* fields are
 * missing, and *which* fields already have somebody's data in them (#812).
 * "Your template is invalid" costs the club an afternoon; "rebuild with
 * `--pdf-forms --uncompressed-pdf`", "the field `iban_last4` is missing" or
 * "`iban` still holds a value" costs them a minute.
 */
final class UnusableTemplateException extends BusinessRuleException
{
    /**
     * How many field names a refusal names before it stops.
     *
     * A template published with data in it usually has data in *all* of it —
     * the fixture's comb alone is 22 cells — and a sentence naming thirty-four
     * fields is one nobody reads to the end. Five is enough to recognise the
     * document; the fix is the same for the sixth.
     */
    private const FIELDS_NAMED = 5;

    /**
     * @param list<string> $missingFields
     * @param list<string> $prefilledFields
     */
    public function __construct(
        public readonly ?TemplateProblem $problem,
        public readonly array $missingFields = [],
        string $message = '',
        public readonly array $prefilledFields = [],
    ) {
        $reason = match (true) {
            $prefilledFields !== [] => BusinessRuleReason::DOCUMENT_TEMPLATE_PREFILLED,
            $missingFields !== [] => BusinessRuleReason::DOCUMENT_TEMPLATE_FIELD_MISSING,
            default => $problem?->reason() ?? BusinessRuleReason::DOCUMENT_TEMPLATE_UNREADABLE,
        };

        $named = $prefilledFields !== [] ? $prefilledFields : $missingFields;

        parent::__construct(
            $reason,
            $message !== '' ? $message : self::describe($problem, $missingFields, $prefilledFields),
            // What the translated sentence interpolates. `fields` is a joined
            // string rather than a list: it is going into a sentence, and the
            // panel has no business re-deciding how a German list is punctuated.
            $named !== []
                ? ['fields' => self::nameFields($named)]
                : ['problem' => $problem?->value ?? 'unknown'],
        );
    }

    /**
     * @param list<string> $missingFields
     * @param list<string> $prefilledFields
     */
    private static function describe(
        ?TemplateProblem $problem,
        array $missingFields,
        array $prefilledFields = [],
    ): string {
        if ($prefilledFields !== []) {
            return 'The mandate template already carries values in its fields: '
                . self::nameFields($prefilledFields);
        }

        if ($missingFields !== []) {
            return 'The mandate template is missing required fields: ' . self::nameFields($missingFields);
        }

        return match ($problem) {
            TemplateProblem::NOT_A_PDF =>
                'The configured document URL did not return a PDF.',
            TemplateProblem::COMPRESSED_OBJECT_STREAMS =>
                'The mandate template uses compressed object streams; rebuild it with --uncompressed-pdf.',
            TemplateProblem::NO_CLASSIC_XREF =>
                'The mandate template has no classic cross-reference table; rebuild it with --uncompressed-pdf.',
            TemplateProblem::NO_FORM_FIELDS =>
                'The mandate template carries no AcroForm fields; rebuild it with --pdf-forms.',
            null => 'The mandate template could not be read.',
        };
    }

    /** @param list<string> $fields */
    private static function nameFields(array $fields): string
    {
        $named = implode(', ', array_slice($fields, 0, self::FIELDS_NAMED));

        return count($fields) > self::FIELDS_NAMED
            ? $named . ' (+' . (count($fields) - self::FIELDS_NAMED) . ')'
            : $named;
    }
}
