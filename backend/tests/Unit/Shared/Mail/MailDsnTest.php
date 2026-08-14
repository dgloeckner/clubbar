<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Mail;

use App\Shared\Mail\InvalidMailDsnException;
use App\Shared\Mail\MailDsn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MailDsnTest extends TestCase
{
    #[DataProvider('validDsns')]
    public function test_parses_supported_schemes(string $dsn, string $scheme, ?string $host, ?int $port): void
    {
        $parsed = MailDsn::parse($dsn);

        $this->assertSame($scheme, $parsed->scheme);
        $this->assertSame($host, $parsed->host);
        $this->assertSame($port, $parsed->port);
    }

    public static function validDsns(): array
    {
        return [
            'smtp with credentials' => ['smtp://user:secret@mail.example.org:587', 'smtp', 'mail.example.org', 587],
            'smtp without port' => ['smtp://mail.example.org', 'smtp', 'mail.example.org', null],
            'smtps' => ['smtps://user:secret@mail.example.org:465', 'smtps', 'mail.example.org', 465],
            'native MTA' => ['native://default', 'native', 'default', null],
            'sendmail' => ['sendmail://default', 'sendmail', 'default', null],
            'explicit discard' => ['null://null', 'null', 'null', null],
            'uppercase scheme' => ['SMTP://mail.example.org', 'smtp', 'mail.example.org', null],
            'surrounding whitespace' => ['  smtp://mail.example.org  ', 'smtp', 'mail.example.org', null],
        ];
    }

    #[DataProvider('invalidDsns')]
    public function test_rejects_unusable_values(string $dsn, string $expectedFragment): void
    {
        $this->expectException(InvalidMailDsnException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedFragment, '/') . '/i');

        MailDsn::parse($dsn);
    }

    public static function invalidDsns(): array
    {
        return [
            'no scheme at all' => ['mail.example.org', 'missing a scheme'],
            'bare host and port' => ['mail.example.org:587', 'missing a scheme'],
            // The typo that actually happens.
            'transposed scheme' => ['smpt://mail.example.org', 'unsupported scheme "smpt"'],
            'a protocol we do not speak' => ['https://mail.example.org', 'unsupported scheme "https"'],
            'scheme and nothing else' => ['smtp://', 'could not be parsed'],
            'empty authority' => ['smtp:///queue', 'could not be parsed'],
        ];
    }

    public function test_error_message_names_the_supported_schemes(): void
    {
        try {
            MailDsn::parse('smpt://mail.example.org');
            $this->fail('expected InvalidMailDsnException');
        } catch (InvalidMailDsnException $e) {
            // The self-check prints this to somebody who cannot read a stack
            // trace, so it has to say what to write instead.
            $this->assertStringContainsString('smtp', $e->getMessage());
            $this->assertStringContainsString('sendmail', $e->getMessage());
            $this->assertStringContainsString('native', $e->getMessage());
        }
    }

    public function test_redacts_the_password(): void
    {
        $dsn = MailDsn::parse('smtp://kassenwart:hunter2@mail.example.org:587');

        $this->assertStringNotContainsString('hunter2', $dsn->redacted());
        $this->assertStringContainsString('kassenwart', $dsn->redacted());
        $this->assertStringContainsString('mail.example.org', $dsn->redacted());
    }

    public function test_redacts_the_password_in_error_messages_too(): void
    {
        // A malformed DSN is exactly the value most likely to be pasted into a
        // bug report, and it still holds a password.
        try {
            MailDsn::parse('imap://kassenwart:hunter2@mail.example.org');
            $this->fail('expected InvalidMailDsnException');
        } catch (InvalidMailDsnException $e) {
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function test_describes_where_mail_goes(): void
    {
        $this->assertSame(
            'smtp to mail.example.org:587',
            MailDsn::parse('smtp://user:secret@mail.example.org:587')->describe()
        );
        $this->assertSame(
            'smtp to mail.example.org',
            MailDsn::parse('smtp://mail.example.org')->describe()
        );
        $this->assertSame(
            'native to default',
            MailDsn::parse('native://default')->describe()
        );
    }
}
