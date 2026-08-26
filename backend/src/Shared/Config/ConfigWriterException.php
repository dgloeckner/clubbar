<?php

declare(strict_types=1);

namespace App\Shared\Config;

use RuntimeException;

/**
 * The rendered `config.php` would not have said what it was asked to say.
 *
 * Always fatal, and deliberately so. Every other failure in this codebase
 * prefers reporting to throwing — but this one writes the file that holds the
 * database password, and the failure mode it guards is a *syntactically valid*
 * file with a credential silently missing from it. That is not discovered when
 * the file is written; it is discovered when the site stops working, by
 * somebody who has no reason to suspect the installer.
 *
 * Part of #710, epic #686.
 */
final class ConfigWriterException extends RuntimeException
{
    public static function unknownSection(string $section, array $known): self
    {
        return new self(sprintf(
            'config.php has no "%s" section, so a value for it would be written nowhere. '
            . 'The template (config.sample.php) defines: %s.',
            $section,
            implode(', ', $known)
        ));
    }

    public static function unknownKey(string $section, string $key): self
    {
        return new self(sprintf(
            'config.php has no "%s.%s", so that value would be dropped rather than written. '
            . 'Either the key is misspelled, or config.sample.php has been edited and this '
            . 'writer has not been told about the change.',
            $section,
            $key
        ));
    }

    /**
     * The template moved out from under us and substitution silently missed.
     *
     * The check that produces this is the whole reason line-oriented editing of
     * a PHP file is acceptable here: without it, a reformatted template writes
     * a config with no database password and no complaint.
     */
    public static function verificationFailed(string $section, string $key): self
    {
        return new self(sprintf(
            'Refusing to write config.php: after rendering, "%s.%s" does not hold the value it '
            . 'was given. config.sample.php has probably been reformatted in a way this writer '
            . 'no longer recognises — fix the template or the writer, but do not write this file: '
            . 'it would look correct and be missing a credential.',
            $section,
            $key
        ));
    }

    public static function templateUnreadable(string $path): self
    {
        return new self('Cannot read the config template at ' . $path . '.');
    }

    public static function notWritable(string $path): self
    {
        return new self('Cannot write ' . $path . '. Check the directory\'s permissions.');
    }
}
