<?php

declare(strict_types=1);

namespace App\Shared\Config;

/**
 * Writes `config.php` by substituting values into the commented template,
 * rather than by generating a file from scratch.
 *
 * ## What this replaces, and why it mattered
 *
 * `install.php` built the file with `var_export()`. That produces a correct
 * array and nothing else — no comments, no section headings, `array (` on its
 * own line — so a club that used the web installer never saw the guidance in
 * `config.sample.php`. Two artifacts described one file, they had drifted, and
 * the one on disk was the bare one.
 *
 * The visible cost was a question with no good answer: *"how do I add backup
 * credentials after install?"* The installer wrote six of the eight sections,
 * and the two it omitted — `mail` and `backup` — are exactly the two a club
 * configures later, by hand, in a file where a missing comma is a fatal error
 * on a live site.
 *
 * ## The sample is the template, not the output
 *
 * The obvious design is a writer that owns the prose and *generates* the
 * sample. This does the reverse: `config.sample.php` stays hand-authored and
 * values are substituted into it. Prose is easier to maintain in a file than in
 * a string literal, and — the load-bearing reason — there is then exactly one
 * copy of it, so the sample and the written file cannot drift by construction
 * rather than by a test that has to remember to care.
 *
 * The template ships to the package root beside `install.php`
 * (`scripts/build-package.sh`), so the installer has it at runtime.
 *
 * ## Substitution verifies itself
 *
 * Line-oriented editing of PHP is fragile, and its fragile case is silent: a
 * template that has been reformatted no longer matches, the value is not
 * written, and the result is a **syntactically valid file with the database
 * password missing**. Nobody discovers that at write time.
 *
 * So {@see render()} evaluates its own output and compares every value against
 * what it was asked to write, refusing on any mismatch
 * ({@see ConfigWriterException::verificationFailed()}). A template that moved
 * produces an error, never a quietly incomplete config.
 *
 * ## No Composer
 *
 * `install.php` runs long before the autoloader exists and loads its
 * dependencies by path (`DataDirectory`, `SecuritySelfCheck`, `FileModes`).
 * This class keeps that possible: no imports beyond its own exception, no
 * framework, no third-party code.
 *
 * Part of #710, epic #686.
 */
final class ConfigWriter
{
    public function __construct(private readonly string $templatePath)
    {
    }

    /**
     * The template with `$values` substituted in, verified.
     *
     * @param array<string, array<string, mixed>> $values section => key => value
     * @throws ConfigWriterException when a key does not exist, or the rendered
     *         file does not read back as the values it was given
     */
    public function render(array $values): string
    {
        $template = @file_get_contents($this->templatePath);
        if ($template === false) {
            throw ConfigWriterException::templateUnreadable($this->templatePath);
        }

        $lines = explode("\n", $template);
        $sections = self::sectionsIn($lines);

        foreach ($values as $section => $keys) {
            if (!isset($sections[$section])) {
                throw ConfigWriterException::unknownSection($section, array_keys($sections));
            }

            foreach ($keys as $key => $value) {
                $at = self::findKey($lines, $sections[$section], (string) $key);
                if ($at === null) {
                    throw ConfigWriterException::unknownKey($section, (string) $key);
                }

                $lines[$at] = self::renderLine($lines[$at], (string) $key, $value);
            }
        }

        $rendered = implode("\n", $lines);
        self::verify($rendered, $values);

        return $rendered;
    }

    /**
     * Render and write, atomically and mode 0600.
     *
     * `.tmp` then `rename()`, because the alternative is a half-written
     * `config.php` on a live site — the one file whose truncation takes the
     * whole application down rather than degrading it.
     *
     * @param array<string, array<string, mixed>> $values
     * @throws ConfigWriterException
     */
    public function writeTo(string $path, array $values): void
    {
        $rendered = $this->render($values);

        $temporary = $path . '.tmp';
        if (@file_put_contents($temporary, $rendered) !== strlen($rendered)) {
            @unlink($temporary);

            throw ConfigWriterException::notWritable($path);
        }

        // Before the rename, so the file is never briefly readable by others
        // under its final name.
        @chmod($temporary, 0600);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw ConfigWriterException::notWritable($path);
        }

        // Publication, and part of the write rather than a step a caller could
        // forget (ADR-0050). After the rename, so what any reader compiles next
        // is the finished file and never the half-written temporary.
        self::forgetCompiled($path);
    }

    /**
     * The values a config file currently holds, for a caller that is editing
     * rather than creating one.
     *
     * The installer re-runs against an existing installation, and everything it
     * is not asking about on this screen has to survive untouched — a backup
     * step must not cost the club its database password.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        // No invalidation here, deliberately — see ADR-0050. This is the hot
        // side: `index.php` loads config.php on every request, and a check
        // here would be levied forever to serve a writer that runs twice in a
        // deployment's life. Freshness is {@see writeTo()}'s job.
        /** @var mixed $config */
        $config = require $path;

        if (!is_array($config)) {
            return [];
        }

        $sections = [];
        foreach ($config as $section => $keys) {
            if (is_string($section) && is_array($keys)) {
                $sections[$section] = $keys;
            }
        }

        return $sections;
    }

    /**
     * An existing config plus one screen's answers, with the screen winning.
     *
     * Every installer screen writes the *whole* file, so anything a screen does
     * not ask about has to be carried across explicitly. Without this, reaching
     * the backup step on a working installation would rewrite `config.php` with
     * no database password in it — the file loads, the site does not, and
     * nothing points at the installer.
     *
     * Merged one section deep on purpose. A section is a coherent group whose
     * keys are independent, so `backup` gaining a DSN must not disturb
     * `backup`'s recipient keys; replacing a whole section wholesale would be
     * the same bug one level up.
     *
     * An answer of `''` never overwrites a stored value: on a re-run a blank
     * field means "unchanged", not "erase this". That rule is what lets the
     * backup screen decline to echo a live client secret back into the HTML.
     *
     * @param array<string, array<string, mixed>> $existing
     * @param array<string, array<string, mixed>> $answers
     * @return array<string, array<string, mixed>>
     */
    public static function merge(array $existing, array $answers): array
    {
        foreach ($answers as $section => $keys) {
            foreach ($keys as $key => $value) {
                if ($value === '' && ($existing[$section][$key] ?? '') !== '') {
                    continue;
                }

                $existing[$section][$key] = $value;
            }
        }

        // Nothing gains a key it did not have just to hold an empty string.
        foreach ($existing as $section => $keys) {
            foreach ($keys as $key => $value) {
                if ($value === '') {
                    unset($existing[$section][$key]);
                }
            }

            if ($existing[$section] === []) {
                unset($existing[$section]);
            }
        }

        return $existing;
    }

    /**
     * Announce that `$path` has changed, by dropping its compiled copy.
     *
     * **Without this, a read straight after a write returns the previous
     * contents.** `opcache.revalidate_freq` defaults to 2 seconds, and during
     * that window the compiled file is served from cache with the mtime never
     * consulted.
     *
     * Not a theoretical window. `install.php` step 2 writes the database
     * credentials and redirects to step 3, which re-reads the file
     * *milliseconds later* to open the connection it migrates through — so on a
     * re-run through `?update=1`, the step that changes the database migrates
     * the **previous** one and reports success (#714). The same window makes
     * two screens submitted in quick succession, or one screen double-clicked,
     * read stale and write the stale values back.
     *
     * ## Why this is called from the writer and not the reader
     *
     * ADR-0050: **coherence is the writer's responsibility.** A reader never
     * asks whether the configuration changed — it is told, by the only
     * participant that can know. `config.php` is loaded on every request and
     * written a handful of times in a deployment's life, so a check on the read
     * side would be levied forever to serve something that almost never
     * happens; in `index.php` it would defeat compilation caching for this file
     * permanently, on the shared hosting ADR-0031 commits to.
     *
     * The compiled cache is shared across the worker pool, so one writer
     * announcing reaches every reader in it — which is what lets a single call
     * here serve call sites that are never modified.
     *
     * Found by driving the installer rather than by reading it: a blank mail
     * field, which means "keep what is stored", restored a DSN that had been
     * replaced moments earlier.
     */
    private static function forgetCompiled(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    /**
     * Where each top-level section begins and ends, as line indices.
     *
     * Matched on the template's own indentation — a section opens at four
     * spaces and its keys sit at eight — rather than by counting brackets,
     * which would have to understand the `//` examples in the prose that
     * contain brackets of their own.
     *
     * @param list<string> $lines
     * @return array<string, array{0: int, 1: int}>
     */
    private static function sectionsIn(array $lines): array
    {
        $sections = [];
        $open = null;
        $name = '';

        foreach ($lines as $i => $line) {
            if (preg_match("/^    '([a-z_]+)' => \[$/", $line, $m) === 1) {
                $open = $i;
                $name = $m[1];
                continue;
            }

            if ($open !== null && $line === '    ],') {
                $sections[$name] = [$open, $i];
                $open = null;
            }
        }

        return $sections;
    }

    /**
     * The line defining `$key` inside a section, commented or not.
     *
     * An optional key ships commented out — that is how the template says "this
     * has a working default" — so both forms have to be found, and supplying a
     * value uncomments in place rather than appending a second copy.
     *
     * @param list<string> $lines
     * @param array{0: int, 1: int} $bounds
     */
    private static function findKey(array $lines, array $bounds, string $key): ?int
    {
        $quoted = preg_quote($key, '/');

        for ($i = $bounds[0] + 1; $i < $bounds[1]; $i++) {
            if (preg_match("/^        (?:\/\/ )?'{$quoted}' => /", $lines[$i]) === 1) {
                return $i;
            }
        }

        return null;
    }

    private static function renderLine(string $original, string $key, mixed $value): string
    {
        return sprintf("        '%s' => %s,", $key, self::literal($value));
    }

    /**
     * A PHP literal for a value that came from a form or a host.
     *
     * `var_export` for scalars, because a database password is whatever the
     * host generated and hosts generate passwords with quotes and backslashes
     * in them. Lists are rendered by hand so a list of recipient keys reads as
     * one key per line rather than as `array (\n  0 =>`.
     */
    private static function literal(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $items = '';
            foreach ($value as $item) {
                $items .= "\n            " . self::literal($item) . ',';
            }

            return '[' . $items . "\n        ]";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return var_export((string) $value, true);
    }

    /**
     * Read the rendered file back and confirm it says what it was asked to say.
     *
     * See the class docblock: this is what makes substitution into a
     * hand-authored template safe to put in front of a club's database
     * password.
     *
     * @param array<string, array<string, mixed>> $values
     * @throws ConfigWriterException
     */
    private static function verify(string $rendered, array $values): void
    {
        if ($values === []) {
            return;
        }

        $evaluated = self::evaluate($rendered);

        foreach ($values as $section => $keys) {
            foreach ($keys as $key => $expected) {
                $actual = $evaluated[$section][$key] ?? null;

                if ($actual !== $expected) {
                    throw ConfigWriterException::verificationFailed($section, (string) $key);
                }
            }
        }
    }

    /**
     * Evaluate the rendered file in isolation.
     *
     * Through a temporary file and `require` rather than `eval()`: the same
     * code path the application itself uses to load config, so a file that
     * verifies here is a file that loads there.
     *
     * @return array<string, mixed>
     */
    private static function evaluate(string $rendered): array
    {
        $path = tempnam(sys_get_temp_dir(), 'clubbar-config-');
        if ($path === false) {
            return [];
        }

        try {
            file_put_contents($path, $rendered);
            self::forgetCompiled($path);

            /** @var mixed $config */
            $config = require $path;

            return is_array($config) ? $config : [];
        } catch (\Throwable) {
            return [];
        } finally {
            @unlink($path);
        }
    }
}
