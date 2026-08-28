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
        return (string) file_get_contents(self::repositoryPath('package/install.php'));
    }

    /**
     * A path in the repository, whether phpunit runs from a checkout or from
     * the container the workflow documents — which mounts the repo at /repo and
     * the backend at /app, so `../` out of the backend resolves to neither.
     */
    private static function repositoryPath(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/../' . $relative;
        $real = realpath($path);

        if ($real === false || !is_file($real)) {
            $real = '/repo/' . $relative;
        }

        return $real;
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
     * The mail step, for the same reason as the backup one.
     *
     * `mail.dsn` and `backup.*` were the two `config.php` sections the
     * installer never wrote — and they are exactly the two a club configures
     * *after* installing, which is what made "hand-edit a PHP file on a live
     * site" the only answer to switching either on.
     */
    public function test_the_mail_step_validates_the_dsn_before_writing_it(): void
    {
        $post = self::postSection();
        $at = strpos($post, "case '5':");

        $this->assertIsInt($at, 'the mail step lost its POST handler');

        $handler = substr($post, $at, strpos($post, "case '6':") - $at);

        $this->assertStringContainsString('MailDsn::parse(', $handler, 'the mail step is not validating the DSN');
        $this->assertStringContainsString('ConfigWriter::merge(', $handler, 'the mail step is not carrying the existing config forward');
    }

    /**
     * Neither optional step may be a dead end: a club that skips mail has to
     * still reach backups, and one that skips backups has to still reach the
     * end. Both are legitimate states — mail off and backups off are normal,
     * not failures — so skipping must cost nothing.
     */
    public function test_both_optional_steps_can_be_skipped(): void
    {
        $source = self::source();

        $this->assertMatchesRegularExpression(
            '/Skip for now/',
            $source,
            'an optional step lost its skip link'
        );
        $this->assertSame(2, substr_count($source, 'Skip for now'), 'expected a skip link on mail and on backups');
    }

    /**
     * The scheduler step must ask for the drain's monitor URL, and validate it.
     *
     * `cron.heartbeat_url` is the alarm that says a scheduler stopped (ADR-0038
     * rule 6) — and the installer wrote every other key of that section, the
     * secret included, while leaving this one to a club hand-editing
     * `config.php` on a live site (#743). Its failure mode is the worst of the
     * three optional screens: a monitor that was never configured, or was
     * configured with a typo, is silent in exactly the same way a working one
     * is, right up until the outage it existed to catch.
     */
    public function test_the_scheduler_step_writes_and_validates_the_heartbeat_url(): void
    {
        $post = self::postSection();
        $at = strpos($post, "case '7':");

        $this->assertIsInt($at, 'the scheduler step lost its POST handler, so its monitor URL is discarded');

        $handler = substr($post, $at);

        $this->assertStringContainsString(
            'installerHeartbeatUrlError(',
            $handler,
            'the scheduler step is not validating the monitor URL — a mistyped one pings nowhere, silently'
        );
        $this->assertStringContainsString(
            'ConfigWriter::merge(',
            $handler,
            'the scheduler step is not carrying the existing config forward'
        );
        $this->assertStringContainsString(
            'heartbeat_url',
            $handler,
            'the scheduler step is not writing cron.heartbeat_url'
        );
    }

    /**
     * And the field has to exist on the page that submits it.
     *
     * A handler with nothing posting to it is the same defect as a form with
     * no handler, one direction over: the value can never be set.
     */
    public function test_the_scheduler_step_renders_the_heartbeat_field(): void
    {
        $source = self::source();

        $this->assertStringContainsString(
            'name="cron_heartbeat_url"',
            $source,
            'step 7 must offer the monitor URL field its own handler reads'
        );
        $this->assertMatchesRegularExpression(
            '/action="\?step=7/',
            $source,
            'the monitor URL field must post back to step 7'
        );
    }

    /**
     * The claim and the thing claimed, as a pair.
     *
     * `docs/deployment.md` tells a club the installer asks for this URL. A doc
     * that says "configure it from the installer, not a text editor" while the
     * installer does not ask is worse than no doc: the club looks for a field
     * that is not there and concludes it configured monitoring when it did not.
     */
    public function test_the_docs_claim_matches_what_the_installer_offers(): void
    {
        $deployment = (string) file_get_contents(self::repositoryPath('docs/deployment.md'));

        $this->assertMatchesRegularExpression(
            '/step=7&update=1/',
            $deployment,
            'docs/deployment.md no longer points at the installer for cron.heartbeat_url. If that '
            . 'is deliberate, the field on step 7 goes with it.'
        );
    }

    /**
     * The cron secret has exactly one writer, and this is it (#744).
     *
     * Until #744 there were two: this file, and a rotate button in Settings →
     * Mail that stored a hash which *superseded* `config.php` entirely. So the
     * wizard could print scheduler instructions for a secret the application
     * had stopped accepting, and the panel could report "no secret configured"
     * over an installation whose `config.php` had carried one since step 2.
     * The panel half is gone; what makes that safe is that the surviving half
     * can both write the value and hand it to the operator.
     */
    public function test_the_scheduler_step_can_rotate_the_cron_secret(): void
    {
        $post = self::postSection();
        $at = strpos($post, "case '7':");

        $this->assertIsInt($at, 'the scheduler step lost its POST handler');

        $handler = substr($post, $at);

        $this->assertStringContainsString(
            "'rotate_cron_secret'",
            $handler,
            'step 7 no longer handles the rotate action, so config.php\'s cron.secret has no writer '
            . 'after step 2 — and no admin-panel one either, since #744 removed it'
        );
        $this->assertStringContainsString(
            'installerRotateCronSecret(',
            $handler,
            'the rotate action is not going through installerRotateCronSecret()'
        );
        $this->assertStringContainsString(
            "\$_SESSION['installer_cron_secret']",
            $handler,
            'the new secret must be handed to the render through the session — a query string would '
            . 'write it into the access log the header form exists to keep it out of'
        );
    }

    /**
     * The button that posts that action, and the one-time display.
     *
     * A generated secret nobody is shown is worse than none: `config.php` has
     * the only copy, the operator cannot paste it into a hosting panel, and
     * whatever URL trigger they had already scheduled has just stopped working.
     */
    public function test_the_scheduler_step_renders_the_rotate_control(): void
    {
        $render = self::source();

        $this->assertStringContainsString(
            'name="action" value="rotate_cron_secret"',
            $render,
            'step 7 must offer the button its own handler reads'
        );
        $this->assertStringContainsString(
            'id="cronSecretValue"',
            $render,
            'a rotated secret must be printed once — config.php keeps the only other copy'
        );
        $this->assertStringContainsString(
            "unset(\$_SESSION['installer_cron_secret']",
            $render,
            'the secret must be cleared from the session as it is rendered, so a refresh does not reprint it'
        );
    }

    /**
     * Rotation retires a legacy panel-rotated hash in the same action.
     *
     * `mail_config.cron_secret_hash` still wins where an installation has one
     * (see MailConfigService::verifyCronSecret) — deliberately, because that is
     * what its scheduler is sending. Which means a rotation that writes only
     * the file hands the operator a secret the application will refuse: the
     * precise failure #744 is about, reintroduced by the fix for it.
     */
    public function test_rotating_retires_a_panel_rotated_secret(): void
    {
        $source = self::source();
        $at = strpos($source, 'function installerRotateCronSecret(');

        $this->assertIsInt($at, 'installerRotateCronSecret() is gone');

        $body = substr($source, $at, strpos($source, "\n/**", $at) - $at);

        $this->assertStringContainsString(
            'cron_secret_hash = NULL',
            $body,
            'a rotation that leaves mail_config.cron_secret_hash in place hands the operator a secret '
            . 'that an older panel rotation still overrides'
        );
        $write = strpos($body, 'writeTo(');
        $clear = strpos($body, 'cron_secret_hash = NULL');

        $this->assertIsInt($write, 'the rotation no longer writes config.php through ConfigWriter');
        $this->assertIsInt($clear);
        $this->assertLessThan(
            $clear,
            $write,
            'the file is written before the row is cleared: a failure between the two must leave the '
            . 'working credential working, not retire it with no replacement published'
        );
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

        // Bounded by the next case rather than running to the end of the
        // section: the scheduler step below writes through the same writer, and
        // an unbounded slice would let this guard pass on *its* call.
        $handler = substr($post, $at, strpos($post, "case '7':") - $at);

        $this->assertStringContainsString('ConfigWriter::merge(', $handler, 'the backup step is not carrying the existing config forward');
        $this->assertStringContainsString('BackupDsn::parse(', $handler, 'the backup step is not validating the DSN');
        $this->assertStringContainsString('BackupKeyring', $handler, 'the backup step is not validating the recipient keys');
    }
}
