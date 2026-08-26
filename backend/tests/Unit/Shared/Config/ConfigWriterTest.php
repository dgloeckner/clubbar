<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Config;

use App\Shared\Config\ConfigWriter;
use App\Shared\Config\ConfigWriterException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * Writing `config.php` without destroying what makes it readable.
 *
 * ## The failure this replaces
 *
 * `install.php` built the file with `var_export()`, which produces a correct
 * array and nothing else: no comments, no section headings, `array (` on its
 * own line. The club that used the web installer therefore never saw the 271
 * lines of guidance in `config.sample.php` — the two artifacts described one
 * file and had drifted, and the installer's version was the one on disk.
 *
 * That is why *"how do I add backup credentials after install?"* had no good
 * answer: the installer wrote six of the eight sections, and the two it left
 * out were the two a club configures later.
 *
 * ## Why the sample is the template rather than the output
 *
 * The obvious design is a writer that owns the prose and *generates* the
 * sample. This does the opposite: `config.sample.php` stays hand-authored and
 * the writer substitutes values into it. Prose is easier to edit in a file than
 * in a string literal, and — the real reason — there is then only one copy, so
 * the two cannot drift by construction rather than by test.
 *
 * ## The safety property that makes substitution acceptable
 *
 * Line-oriented editing of PHP is fragile, and the fragile case is silent: a
 * reformatted template that no longer matches would drop the database password
 * and still write a syntactically valid file. So the writer **verifies its own
 * output** — it evaluates the rendered file and refuses to hand back anything
 * whose values are not exactly what was asked for. A template that has moved
 * out from under it produces a refusal, never a config missing a credential.
 *
 * Part of #710, epic #686.
 */
class ConfigWriterTest extends TestCase
{
    use TempTree;

    private ConfigWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new ConfigWriter(self::templatePath());
    }

    /**
     * The shipped sample is the zero case: no values means the file a club
     * would have hand-copied, unchanged. If this ever fails, the writer has
     * started imposing its own formatting on the template.
     */
    public function test_no_values_reproduces_the_template_unchanged(): void
    {
        $this->assertSame(
            (string) file_get_contents(self::templatePath()),
            $this->writer->render([])
        );
    }

    public function test_a_value_replaces_the_empty_placeholder(): void
    {
        $rendered = $this->writer->render(['db' => ['user' => 'clubbar', 'pass' => 'hunter2']]);

        $this->assertStringContainsString("'user' => 'clubbar',", $rendered);
        $this->assertStringContainsString("'pass' => 'hunter2',", $rendered);
    }

    /** The whole point: the guidance is still there afterwards. */
    public function test_the_prose_survives_substitution(): void
    {
        $rendered = $this->writer->render(['db' => ['pass' => 'hunter2']]);

        $this->assertStringContainsString('Club Bar Configuration', $rendered);
        $this->assertStringContainsString('WHO CAN OPEN AN ARCHIVE', $rendered);
        // A comment that sits directly above a key we just rewrote.
        $this->assertStringContainsString('openssl rand -hex 32', $rendered);
    }

    /**
     * Optional keys ship commented out, which is how the template says "this
     * has a working default". Supplying one has to uncomment it, not append a
     * second copy below the comment.
     */
    public function test_a_commented_optional_key_is_uncommented_when_given_a_value(): void
    {
        $rendered = $this->writer->render(['session' => ['save_path' => '/srv/sessions']]);

        $this->assertStringContainsString("'save_path' => '/srv/sessions',", $rendered);
        $this->assertStringNotContainsString("// 'save_path' =>", $rendered);
        $this->assertSame(1, substr_count($rendered, "'save_path' =>"));
    }

    public function test_a_list_value_renders_as_a_php_list(): void
    {
        $rendered = $this->writer->render([
            'backup' => ['recipient_public_keys' => ['admin:' . str_repeat('a', 64), 'vorstand:' . str_repeat('b', 64)]],
        ]);

        $config = $this->evaluate($rendered);

        $this->assertSame(
            ['admin:' . str_repeat('a', 64), 'vorstand:' . str_repeat('b', 64)],
            $config['backup']['recipient_public_keys']
        );
    }

    public function test_the_result_is_valid_php_that_evaluates_to_the_expected_array(): void
    {
        $config = $this->evaluate($this->writer->render([
            'db' => ['host' => 'db.example.org', 'port' => 3307, 'name' => 'clubbar', 'user' => 'u', 'pass' => 'p'],
            'app' => ['env' => 'production', 'debug' => false, 'url' => 'https://bar.example.org'],
        ]));

        $this->assertSame('db.example.org', $config['db']['host']);
        $this->assertSame('clubbar', $config['db']['name']);
        $this->assertSame('https://bar.example.org', $config['app']['url']);
    }

    /** Ints stay ints and bools stay bools — a quoted `3306` would still connect, a quoted `false` would not. */
    public function test_scalars_keep_their_types(): void
    {
        $config = $this->evaluate($this->writer->render([
            'db' => ['port' => 3307],
            'app' => ['debug' => true],
            'session' => ['max_age' => 1800],
        ]));

        $this->assertSame(3307, $config['db']['port']);
        $this->assertTrue($config['app']['debug']);
        $this->assertSame(1800, $config['session']['max_age']);
    }

    /**
     * A database password is whatever the host generated, and hosts generate
     * passwords with quotes and backslashes in them. Getting this wrong writes
     * a file that either fails to parse or silently truncates the credential.
     */
    public function test_a_value_containing_quotes_or_backslashes_survives_intact(): void
    {
        $nasty = "p'a\\ss\"w0rd\$x";

        $config = $this->evaluate($this->writer->render(['db' => ['pass' => $nasty]]));

        $this->assertSame($nasty, $config['db']['pass']);
    }

    /**
     * **The property that makes line-oriented substitution safe to ship.**
     *
     * If the template is reformatted or a key renamed, a writer that simply
     * failed to match would produce a valid-looking file with no database
     * password in it — discovered when the site stops working, not when the
     * file is written. Refusing is the only acceptable outcome.
     */
    public function test_a_key_the_template_does_not_have_is_refused_rather_than_dropped(): void
    {
        $this->expectException(ConfigWriterException::class);
        $this->expectExceptionMessageMatches('/db\.hostname|hostname/');

        $this->writer->render(['db' => ['hostname' => 'typo.example.org']]);
    }

    public function test_a_section_the_template_does_not_have_is_refused(): void
    {
        $this->expectException(ConfigWriterException::class);
        $this->expectExceptionMessageMatches('/telemetry/');

        $this->writer->render(['telemetry' => ['endpoint' => 'https://example.org']]);
    }

    /**
     * Writing is atomic and the file is not world-readable: it holds the
     * database password, and on the fallback layout it sits inside the
     * document root (ADR-0031 decision 2).
     */
    public function test_writing_produces_a_file_that_loads_back_with_the_same_values(): void
    {
        $dir = self::makeTempTree('config-writer');

        try {
            $path = $dir . '/config.php';
            $this->writer->writeTo($path, ['db' => ['pass' => 's3cret'], 'cron' => ['secret' => 'abc123']]);

            $config = require $path;

            $this->assertSame('s3cret', $config['db']['pass']);
            $this->assertSame('abc123', $config['cron']['secret']);
            $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        } finally {
            self::removeTempTree($dir);
        }
    }

/**
     * **The scenario this whole change exists for.**
     *
     * The installer writes the *whole* file on every screen. So reaching the
     * backup step on a working installation — months after install, which is
     * when a club actually thinks about backups — must not cost it the database
     * password, the TOTP encryption key or the cron secret.
     *
     * Getting this wrong produces a `config.php` that still loads and a site
     * that no longer starts, with nothing pointing at the installer as the
     * cause.
     */
    public function test_a_later_screen_adds_its_values_without_dropping_the_earlier_ones(): void
    {
        $dir = self::makeTempTree('config-two-steps');

        try {
            $path = $dir . '/config.php';
            $password = "p'w\\x";

            // Step 2, at install time.
            $this->writer->writeTo($path, [
                'db' => ['host' => 'db.example.org', 'name' => 'clubbar', 'user' => 'u', 'pass' => $password],
                'security' => ['totp_encryption_key' => str_repeat('a', 64)],
                'cron' => ['secret' => str_repeat('c', 64)],
            ]);

            // Step 6, months later, through ?update=1 — carrying the existing
            // file forward the way install.php's configValuesToWrite() does.
            $existing = ConfigWriter::read($path);
            $existing['backup'] = array_merge($existing['backup'] ?? [], [
                'dsn' => 'msgraph://tenant/client@drive/b!x/clubbar',
                'recipient_public_keys' => ['admin:' . str_repeat('d', 64)],
            ]);
            $this->writer->writeTo($path, $existing);

            $config = require $path;

            $this->assertSame($password, $config['db']['pass'], 'the database password was lost');
            $this->assertSame(str_repeat('a', 64), $config['security']['totp_encryption_key']);
            $this->assertSame(str_repeat('c', 64), $config['cron']['secret']);
            $this->assertSame('msgraph://tenant/client@drive/b!x/clubbar', $config['backup']['dsn']);
            $this->assertCount(1, $config['backup']['recipient_public_keys']);
        } finally {
            self::removeTempTree($dir);
        }
    }

    /**
     * A blank field on a re-run means "unchanged", never "erase this".
     *
     * This is what lets the backup screen decline to echo a live client secret
     * back into an HTML value attribute: the field renders empty, the club
     * submits it empty, and the stored secret survives. Without the rule, the
     * safe rendering choice would silently delete the credential the screen
     * exists to manage.
     */
    public function test_a_blank_answer_keeps_the_stored_value(): void
    {
        $merged = ConfigWriter::merge(
            ['backup' => ['dsn' => 'msgraph://t/c@drive/b!x/clubbar', 'client_secret' => 'stored']],
            ['backup' => ['dsn' => 'msgraph://t/c@drive/b!y/archive', 'client_secret' => '']]
        );

        $this->assertSame('msgraph://t/c@drive/b!y/archive', $merged['backup']['dsn']);
        $this->assertSame('stored', $merged['backup']['client_secret']);
    }

    /**
     * Merged one section deep, not one level: `backup` gaining a DSN must not
     * cost it the recipient keys that were already there. Replacing a whole
     * section wholesale would be the same bug one level up from the one this
     * class exists to prevent.
     */
    public function test_answers_merge_into_a_section_rather_than_replacing_it(): void
    {
        $merged = ConfigWriter::merge(
            ['db' => ['host' => 'db.example.org', 'pass' => 'p'], 'backup' => ['recipient_public_keys' => ['admin:x']]],
            ['backup' => ['dsn' => 'msgraph://t/c@drive/b!x/clubbar']]
        );

        $this->assertSame(['admin:x'], $merged['backup']['recipient_public_keys']);
        $this->assertSame('msgraph://t/c@drive/b!x/clubbar', $merged['backup']['dsn']);
        $this->assertSame('p', $merged['db']['pass'], 'an untouched section was disturbed');
    }

    /**
     * An empty answer for something never configured writes nothing at all.
     *
     * Every key the writer is handed has to exist in the template, so a screen
     * that dutifully passed `'' ` for each of its skipped fields would turn a
     * blank optional field into a rendered `'heartbeat_url' => ''` — noise in
     * the file the club reads, and, for a key the template ships commented out,
     * a line that now claims to be set.
     */
    public function test_a_blank_answer_for_something_unset_adds_no_key(): void
    {
        $merged = ConfigWriter::merge(
            ['db' => ['pass' => 'p']],
            ['backup' => ['dsn' => '', 'client_secret' => '', 'heartbeat_url' => '']]
        );

        $this->assertArrayNotHasKey('backup', $merged);
        $this->assertSame(['db' => ['pass' => 'p']], $merged);
    }

    /**
     * The merged result is what the writer is actually handed, so it has to be
     * a shape the writer accepts — every key still a real template key.
     */
    public function test_a_merged_result_renders(): void
    {
        $config = $this->evaluate($this->writer->render(ConfigWriter::merge(
            ['db' => ['host' => 'db.example.org', 'pass' => "p'w\\x"], 'cron' => ['secret' => 'abc']],
            ['backup' => ['dsn' => 'msgraph://t/c@drive/b!x/clubbar', 'client_secret' => 'shh']]
        )));

        $this->assertSame("p'w\\x", $config['db']['pass']);
        $this->assertSame('abc', $config['cron']['secret']);
        $this->assertSame('shh', $config['backup']['client_secret']);
    }

    /** @return array<string,mixed> */
    private function evaluate(string $rendered): array
    {
        $dir = self::makeTempTree('config-eval');

        try {
            $path = $dir . '/rendered.php';
            file_put_contents($path, $rendered);
            $config = require $path;

            $this->assertIsArray($config);

            return $config;
        } finally {
            self::removeTempTree($dir);
        }
    }

    private static function templatePath(): string
    {
        // backend/tests/Unit/Shared/Config → repo root
        $checkout = dirname(__DIR__, 5);
        if (is_file($checkout . '/package/config.sample.php')) {
            return $checkout . '/package/config.sample.php';
        }

        // The documented container workflow mounts the repo at /repo.
        return '/repo/package/config.sample.php';
    }
}
