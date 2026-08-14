<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Mail;

use App\Shared\Logging\Logger;
use App\Shared\Mail\MailMessage;
use App\Shared\Mail\MailSender;
use App\Shared\Mail\NullTransport;
use PHPUnit\Framework\TestCase;

class NullTransportTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/clubbar-null-transport-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->logDir);
    }

    public function test_reports_a_permanent_failure_rather_than_a_false_success(): void
    {
        $result = $this->transport()->send($this->message(), $this->sender());

        // Reporting "sent" would stamp a sent_at on a message nobody received,
        // and that timestamp is the club's own proof it announced a collection.
        $this->assertFalse($result->sent);
        // Not transient either: no amount of retrying configures a DSN.
        $this->assertFalse($result->transient);
        $this->assertStringContainsString('mail.dsn', (string) $result->error);
    }

    public function test_never_throws(): void
    {
        $this->expectNotToPerformAssertions();

        $this->transport()->send($this->message(), $this->sender());
    }

    public function test_logs_a_warning(): void
    {
        $this->transport()->send($this->message(), $this->sender());

        $log = implode('', array_map(fn ($f) => (string) file_get_contents($f), glob($this->logDir . '/*.log') ?: []));

        $this->assertStringContainsString('WARNING', $log);
        $this->assertStringContainsString('Mail not sent', $log);
    }

    public function test_describes_itself_as_unconfigured(): void
    {
        $this->assertStringContainsString('unset', $this->transport()->describe());
    }

    private function transport(): NullTransport
    {
        return new NullTransport(new Logger($this->logDir));
    }

    private function message(): MailMessage
    {
        return new MailMessage(
            to: 'member@example.org',
            subject: 'Vorabankündigung',
            html: '<p>x</p>',
            text: 'x',
        );
    }

    private function sender(): MailSender
    {
        return new MailSender('bar@example.org', 'Ruderverein Beispiel', 'kassenwart@example.org');
    }
}
