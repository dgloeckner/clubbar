<?php

declare(strict_types=1);

namespace Tests\Unit\Package;

use PHPUnit\Framework\TestCase;

/**
 * Structural guards on `package/install.php`, which nothing else can test.
 *
 * The installer is a single procedural script that writes `config.php`, runs
 * migrations and creates the first admin. It cannot be unit tested the usual
 * way — including it *performs* an install — so its shape is asserted instead.
 *
 * ## The defect these guards exist for
 *
 * `install.php` has two `switch ($step)` blocks and they do opposite things:
 * one **acts** on a POST and redirects, the other **renders** a page. Adding a
 * step means touching both, and putting a handler in the render switch produces
 * a file that passes `php -l` and is thoroughly broken:
 *
 * - a second `case '6':` in the same switch is legal PHP, so the render case is
 *   simply unreachable and the step's page can never be shown;
 * - the handler's `header('Location: …')` runs after the page's HTML has
 *   already been sent, so the redirect never happens;
 * - the handler reads variables that exist at file scope and not inside
 *   `renderPage()`, so it silently sees nulls.
 *
 * None of that is visible from a syntax check, and it is not visible from
 * reading either switch on its own.
 *
 * Part of #710, epic #686.
 */
class InstallerStructureTest extends TestCase
{
    private static function source(): string
    {
        $path = dirname(__DIR__, 3) . '/../package/install.php';
        $real = realpath($path);

        if ($real === false || !is_file($real)) {
            // The documented container workflow mounts the repo at /repo.
            $real = '/repo/package/install.php';
        }

        return (string) file_get_contents($real);
    }

    /** The acting half: from the POST guard to the render call. */
    private static function postSection(): string
    {
        $source = self::source();
        $from = strpos($source, "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {");
        $to = strpos($source, '// --- Render page ---');

        self::assertIsInt($from, 'the POST section is no longer recognisable');
        self::assertIsInt($to, 'the render call is no longer recognisable');

        return substr($source, $from, $to - $from);
    }

    /** The rendering half: the body of `renderPage()`. */
    private static function renderSection(): string
    {
        $source = self::source();
        $from = strpos($source, 'function renderPage(');
        $to = strpos($source, 'function renderStep1(');

        self::assertIsInt($from);
        self::assertIsInt($to);

        return substr($source, $from, $to - $from);
    }

    /**
     * **The guard that catches a handler pasted into the wrong switch.**
     *
     * A duplicate label is legal PHP and silently unreachable, so this failure
     * mode reaches a club as "step 6 shows step 6's handler doing nothing".
     */
    public function test_neither_step_switch_repeats_a_case_label(): void
    {
        foreach (['POST' => self::postSection(), 'render' => self::renderSection()] as $which => $section) {
            preg_match_all("/^\s*case '(\d)':/m", $section, $m);

            $this->assertSame(
                array_values(array_unique($m[1])),
                $m[1],
                sprintf('the %s switch has a duplicate case label: %s', $which, implode(', ', $m[1]))
            );
        }
    }

    /**
     * Rendering must not act. A `header()` call in there fires after the page
     * has been sent, and a `$_POST` read there is a handler in the wrong half.
     */
    public function test_the_render_switch_only_renders(): void
    {
        $render = self::renderSection();

        $this->assertStringNotContainsString('$_POST', $render, 'renderPage() is handling a submission');
        $this->assertStringNotContainsString("header('Location:", $render, 'renderPage() is redirecting');
    }

    /**
     * Every step that can be submitted has a page to submit from, and every
     * page a club is sent to exists. A handler with no render case is a step
     * nobody can reach; a render case with no handler is a form that discards
     * what was typed into it.
     */
    public function test_every_step_a_page_redirects_to_can_be_rendered(): void
    {
        preg_match_all("/\?step=(\d)/", self::source(), $targets);
        preg_match_all("/^\s*case '(\d)':/m", self::renderSection(), $rendered);

        $missing = array_diff(array_unique($targets[1]), $rendered[1]);

        $this->assertSame([], array_values($missing), 'a link or redirect points at a step with no page');
    }

    /**
     * The backup step is the reason `?update=1` matters: a club configures
     * backups long after installing, and the alternative is hand-editing
     * `config.php` on a live site.
     */
    public function test_the_backup_step_writes_through_the_config_writer(): void
    {
        $post = self::postSection();
        $at = strpos($post, "case '6':");

        $this->assertIsInt($at, 'the backup step lost its POST handler');

        $handler = substr($post, $at);

        $this->assertStringContainsString('ConfigWriter::merge(', $handler, 'the backup step is not carrying the existing config forward');
        $this->assertStringContainsString('BackupDsn::parse(', $handler, 'the backup step is not validating the DSN');
        $this->assertStringContainsString('BackupKeyring', $handler, 'the backup step is not validating the recipient keys');
    }
}
