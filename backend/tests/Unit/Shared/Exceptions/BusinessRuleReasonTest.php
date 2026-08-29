<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Exceptions;

use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use PHPUnit\Framework\TestCase;

/**
 * The refusal contract (#757).
 *
 * A `BusinessRuleException`'s message is English, for the log and for a raw
 * API caller. The admin panel is neither, so it renders `errors.reasons.<code>`
 * from its own locale files. Two things have to hold for that to work, and
 * both are checked here rather than in review: every refusal names a reason
 * (the constructor requires one, so this only has to prove it is kept), and no
 * reason smuggles a formatted amount into its params — a pre-formatted "€7.50"
 * would land inside a German sentence exactly as the original bug did.
 */
final class BusinessRuleReasonTest extends TestCase
{
    public function test_a_refusal_carries_its_reason_and_params(): void
    {
        $e = new BusinessRuleException(
            BusinessRuleReason::MEMBER_BALANCE_OUTSTANDING,
            'Cannot anonymize: outstanding balance of €7.50',
            ['balance_cents' => 750],
        );

        $this->assertSame(BusinessRuleReason::MEMBER_BALANCE_OUTSTANDING, $e->getReason());
        $this->assertSame(['balance_cents' => 750], $e->getParams());
        $this->assertSame(409, $e->getHttpStatusCode());
        $this->assertSame('business_rule_violation', $e->getErrorCode());
        $this->assertSame('Cannot anonymize: outstanding balance of €7.50', $e->getMessage());
    }

    public function test_a_refusal_keeps_the_failure_it_translates(): void
    {
        // A unique-constraint violation reaching the caller as a 409 must not
        // lose the PDOException underneath it — that is what the log needs.
        $previous = new \RuntimeException('SQLSTATE[23000]');
        $e = new BusinessRuleException(BusinessRuleReason::MEMBERS_ALREADY_REVERSED, 'already reversed', [], $previous);

        $this->assertSame($previous, $e->getPrevious());
    }

    public function test_every_reason_reads_as_a_wire_value(): void
    {
        // The value is the API contract and the locale-file key. Anything but
        // lower snake_case would work here and read wrong in both places.
        foreach (BusinessRuleReason::cases() as $reason) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $reason->value, $reason->name);
        }
    }

    public function test_no_two_reasons_share_a_value(): void
    {
        $values = array_map(static fn(BusinessRuleReason $r) => $r->value, BusinessRuleReason::cases());

        $this->assertSame($values, array_values(array_unique($values)));
    }

    /**
     * Every `throw new BusinessRuleException(` in the module source names a
     * reason on the same statement.
     *
     * The constructor already makes this a fatal rather than a silent leak, so
     * this is a readability guard: it catches the copy-paste that reaches for a
     * reason "close enough" to the case at hand by naming what each site uses.
     */
    public function test_every_refusal_in_the_source_names_a_reason(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(dirname(__DIR__, 4) . '/src') as $file) {
            $lines = file($file) ?: [];
            foreach ($lines as $i => $line) {
                if (!str_contains($line, 'new BusinessRuleException(')) {
                    continue;
                }
                $statement = implode(' ', array_slice($lines, $i, 3));
                if (!str_contains($statement, 'BusinessRuleReason::') && !str_contains($statement, '$this->reason')) {
                    $offenders[] = basename($file) . ':' . ($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /** @return list<string> */
    private function phpFilesUnder(string $root): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }
}
