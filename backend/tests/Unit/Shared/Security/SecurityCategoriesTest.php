<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Shared\Security\SecuritySelfCheck;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The one list of security-finding categories, kept to one list.
 *
 * Four artifacts name these: `SecuritySelfCheck`'s `CATEGORY_*` constants, the
 * `api/admin.yaml` enum, the admin panel's render order and its locale files.
 * A category present in one and absent from another does not break anything
 * loudly — and that is the problem. The report exists so a protection that
 * stopped working says so, so its worst failure is a row that is measured,
 * returned by the API and never rendered, which reads as "nothing to report".
 *
 * `delivery` (#406) was in exactly that state until #710: reported by the
 * backend, missing from the panel's list, invisible on the page.
 *
 * This asserts the backend half. The frontend half — render order and headings
 * — is asserted in `SecurityCheckTab.categories.test.ts`.
 *
 * Part of #710, epic #686.
 */
class SecurityCategoriesTest extends TestCase
{
    /** @return list<string> */
    private static function declared(): array
    {
        $values = [];

        foreach ((new ReflectionClass(SecuritySelfCheck::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'CATEGORY_')) {
                $values[] = (string) $value;
            }
        }

        sort($values);

        return $values;
    }

    /** @return list<string> */
    private static function specified(): array
    {
        $path = dirname(__DIR__, 4) . '/../api/admin.yaml';
        $real = realpath($path);
        $spec = (string) file_get_contents($real !== false ? $real : '/repo/api/admin.yaml');

        // The one enum in the file that carries the category names. Matched on
        // a member rather than by walking the YAML, so this needs no parser —
        // the backend has none, by contract with install.php.
        self::assertSame(
            1,
            preg_match('/enum: \[(runtime[^\]]*)\]/', $spec, $m),
            'api/admin.yaml no longer declares the SecurityFinding category enum'
        );

        $values = array_map('trim', explode(',', $m[1]));
        sort($values);

        return $values;
    }

    public function test_the_openapi_enum_and_the_constants_are_the_same_list(): void
    {
        $this->assertSame(
            self::specified(),
            self::declared(),
            'a category exists in one place and not the other'
        );
    }

    /**
     * The two categories the engine names but does not produce. Both need
     * something the engine cannot have — the database, or the backup module's
     * parsers — so `SecurityCheckService` appends their rows. The *names* live
     * with the others so there is one list rather than two.
     */
    public function test_the_appended_categories_are_declared_with_the_measured_ones(): void
    {
        $this->assertContains(SecuritySelfCheck::CATEGORY_DELIVERY, self::declared());
        $this->assertContains(SecuritySelfCheck::CATEGORY_BACKUP, self::declared());
    }
}
