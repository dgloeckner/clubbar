<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Mail;

use App\Shared\Config\AppConfig;
use App\Shared\Logging\Logger;
use App\Shared\Mail\InvalidMailDsnException;
use App\Shared\Mail\MailTransportFactory;
use App\Shared\Mail\NullTransport;
use App\Shared\Mail\SymfonyMailerTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The DSN matrix from #401: every configured value resolves to the right
 * transport or to the right typed error.
 *
 * Nothing here opens a socket — the factory builds a transport, and
 * SymfonyMailerTransport defers `Transport::fromDsn()` to the first send
 * precisely so that constructing one is free.
 */
class MailTransportFactoryTest extends TestCase
{
    #[DataProvider('realTransportDsns')]
    public function test_a_configured_dsn_yields_the_symfony_transport(string $dsn, string $expectedDescription): void
    {
        $transport = $this->factory($dsn)->create();

        $this->assertInstanceOf(SymfonyMailerTransport::class, $transport);
        $this->assertSame($expectedDescription, $transport->describe());
    }

    public static function realTransportDsns(): array
    {
        return [
            'smtp' => ['smtp://user:secret@mail.example.org:587', 'smtp to mail.example.org:587'],
            'smtps' => ['smtps://user:secret@mail.example.org:465', 'smtps to mail.example.org:465'],
            'host MTA' => ['native://default', 'native to default'],
            'sendmail' => ['sendmail://default', 'sendmail to default'],
            // Configured *and* deliberately discarding is a real state, and it
            // is not the same as unconfigured.
            'explicit discard' => ['null://null', 'null to null'],
        ];
    }

    #[DataProvider('absentDsns')]
    public function test_an_absent_dsn_yields_the_null_transport(string $configured): void
    {
        $transport = $this->factory($configured)->create();

        $this->assertInstanceOf(NullTransport::class, $transport);
        $this->assertStringContainsString('unset', $transport->describe());
    }

    public static function absentDsns(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
        ];
    }

    public function test_a_malformed_dsn_throws_the_typed_error(): void
    {
        $this->expectException(InvalidMailDsnException::class);

        $this->factory('smpt://mail.example.org')->create();
    }

    public function test_status_reports_instead_of_throwing(): void
    {
        // The self-check renders this while a misconfigured DSN is exactly the
        // thing it exists to report, so it must not be the thing that breaks it.
        $status = $this->factory('smpt://mail.example.org')->status();

        $this->assertTrue($status->configured);
        $this->assertFalse($status->valid);
        $this->assertNotNull($status->error);
        $this->assertStringContainsString('unsupported scheme', $status->error);
    }

    public function test_status_distinguishes_unconfigured_from_misconfigured(): void
    {
        $unconfigured = $this->factory('')->status();
        $this->assertFalse($unconfigured->configured);
        $this->assertFalse($unconfigured->valid);
        $this->assertNull($unconfigured->error);

        $ok = $this->factory('smtp://mail.example.org:587')->status();
        $this->assertTrue($ok->configured);
        $this->assertTrue($ok->valid);
        $this->assertSame('smtp to mail.example.org:587', $ok->summary);
    }

    public function test_status_never_leaks_the_password(): void
    {
        $status = $this->factory('smtp://kassenwart:hunter2@mail.example.org:587')->status();

        $this->assertStringNotContainsString('hunter2', $status->summary);
    }

    private function factory(string $dsn): MailTransportFactory
    {
        $previous = $_ENV['MAIL_DSN'] ?? null;
        $_ENV['MAIL_DSN'] = $dsn;

        try {
            $config = new AppConfig();
        } finally {
            if ($previous === null) {
                unset($_ENV['MAIL_DSN']);
            } else {
                $_ENV['MAIL_DSN'] = $previous;
            }
        }

        return new MailTransportFactory($config, new Logger(sys_get_temp_dir() . '/clubbar-mail-tests'));
    }
}
