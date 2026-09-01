<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Documents;

use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;

/**
 * The club's document cannot be used as a mandate template (#780).
 *
 * A `BusinessRuleException`, so the panel translates it like any other refusal
 * (Pattern 019) — but carrying the two structured facts a sentence needs to be
 * actionable rather than merely polite: *which* problem, and *which* fields are
 * missing. "Your template is invalid" costs the club an afternoon; "rebuild
 * with `--pdf-forms --uncompressed-pdf`" or "the field `iban_last4` is missing"
 * costs them a minute.
 */
final class UnusableTemplateException extends BusinessRuleException
{
    /** @param list<string> $missingFields */
    public function __construct(
        public readonly ?TemplateProblem $problem,
        public readonly array $missingFields = [],
        string $message = '',
    ) {
        $reason = $missingFields !== []
            ? BusinessRuleReason::DOCUMENT_TEMPLATE_FIELD_MISSING
            : ($problem?->reason() ?? BusinessRuleReason::DOCUMENT_TEMPLATE_UNREADABLE);

        parent::__construct(
            $reason,
            $message !== '' ? $message : self::describe($problem, $missingFields),
            // What the translated sentence interpolates. `fields` is a joined
            // string rather than a list: it is going into a sentence, and the
            // panel has no business re-deciding how a German list is punctuated.
            $missingFields !== []
                ? ['fields' => implode(', ', $missingFields)]
                : ['problem' => $problem?->value ?? 'unknown'],
        );
    }

    /** @param list<string> $missingFields */
    private static function describe(?TemplateProblem $problem, array $missingFields): string
    {
        if ($missingFields !== []) {
            return 'The mandate template is missing required fields: ' . implode(', ', $missingFields);
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
}
