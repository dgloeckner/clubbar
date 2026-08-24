<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

/**
 * One column value, back into SQL.
 *
 * Small on purpose: this is the only place a value can be altered on the way
 * into an archive, so it is a pure function with no database, no connection and
 * no configuration. Everything it can get wrong is therefore reachable from a
 * unit test — which matters because getting it wrong does not fail. It produces
 * a file that looks exactly like a backup and restores something subtly
 * different, and nothing says so until the day someone needs it.
 *
 * Two decisions worth stating:
 *
 * **Binary goes out as a hex literal, never as a quoted string.** Escaping
 * assumes text; `mandates.iban_ciphertext` is a libsodium sealed box, arbitrary
 * bytes in no encoding. `X'..'` has no escaping rules to get wrong and no
 * character set to be reinterpreted under, so a sealed IBAN comes back the
 * bytes it went in as — or not at all, rather than one byte different.
 *
 * **Text is escaped with backslashes**, the way mysqldump does it, which is
 * correct only while the restoring session is not in `NO_BACKSLASH_ESCAPES`
 * mode. {@see DatabaseDump} pins the session mode in the archive header for
 * exactly this reason; the two belong together.
 *
 * ADR-0049 decision 1. Part of #688, epic #686.
 */
final class SqlValueEmitter
{
    /**
     * Bytes that must not appear raw inside a quoted literal, and what they
     * become. `\Z` is here because 0x1A terminates input on some Windows
     * clients — the same reason mysqldump escapes it.
     */
    private const ESCAPES = [
        "\\" => '\\\\',
        "'" => "\\'",
        "\0" => '\\0',
        "\n" => '\\n',
        "\r" => '\\r',
        "\x1A" => '\\Z',
    ];

    /**
     * @param string|null $value the raw column value as PDO returned it
     * @param bool $binary true for BINARY/VARBINARY/BLOB columns
     */
    public static function literal(?string $value, bool $binary = false): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($binary) {
            return "X'" . strtoupper(bin2hex($value)) . "'";
        }

        return "'" . strtr($value, self::ESCAPES) . "'";
    }
}
