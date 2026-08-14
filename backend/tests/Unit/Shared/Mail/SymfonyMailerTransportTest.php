<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Mail;

use App\Shared\Logging\Logger;
use App\Shared\Mail\MailDsn;
use App\Shared\Mail\MailMessage;
use App\Shared\Mail\MailSender;
use App\Shared\Mail\SymfonyMailerTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Everything here runs over `null://null`, Symfony's discard transport, so the
 * suite keeps the promise from #401 that no test opens a socket.
 */
class SymfonyMailerTransportTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/clubbar-symfony-transport-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->logDir);
    }

    public function test_sends_over_the_discard_transport(): void
    {
        $result = $this->transport()->send($this->message(), $this->sender());

        $this->assertTrue($result->sent);
        $this->assertNull($result->error);
    }

    public function test_composes_both_parts(): void
    {
        $email = $this->compose($this->message(), $this->sender());

        $this->assertSame('<p>47,50 &euro; am 21.08.2026</p>', $email->getHtmlBody());
        $this->assertSame('47,50 EUR am 21.08.2026', $email->getTextBody());
    }

    public function test_carries_the_configured_sender_and_reply_to(): void
    {
        $email = $this->compose($this->message(), $this->sender());

        $from = $email->getFrom()[0];
        $this->assertSame('bar@example.org', $from->getAddress());
        // The display name is the only part of a mail visible before it is
        // opened; without it the inbox shows a bare address.
        $this->assertSame('Ruderverein Beispiel', $from->getName());

        $this->assertSame('kassenwart@example.org', $email->getReplyTo()[0]->getAddress());
    }

    public function test_embeds_the_club_mark_when_the_template_asks_for_one(): void
    {
        // The alternative to a hosted logo URL: a cid: reference, which needs
        // the file attached to the message.
        $message = new MailMessage(
            to: 'member@example.org',
            subject: 'Vorabankündigung',
            html: '<img src="cid:club-logo">',
            text: 'x',
            embeddedImages: ['club-logo' => __DIR__ . '/../../../../resources/mail/adler-mail.png'],
        );

        $email = $this->compose($message, $this->sender());

        $attachments = $email->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('club-logo', $attachments[0]->getFilename());
    }

    public function test_reply_to_is_optional(): void
    {
        $email = $this->compose($this->message(), new MailSender('bar@example.org', 'Ruderverein Beispiel'));

        $this->assertSame([], $email->getReplyTo());
    }

    public function test_addresses_the_member_by_name_when_there_is_one(): void
    {
        $email = $this->compose($this->message(), $this->sender());

        $to = $email->getTo()[0];
        $this->assertSame('member@example.org', $to->getAddress());
        $this->assertSame('Anna Beispiel', $to->getName());
    }

    public function test_an_unusable_recipient_is_a_permanent_failure_not_an_exception(): void
    {
        // One member's broken address must not take down the other forty-six
        // announcements in the batch.
        $message = new MailMessage(
            to: 'not an address',
            subject: 'Vorabankündigung',
            html: '<p>x</p>',
            text: 'x',
        );

        $result = $this->transport()->send($message, $this->sender());

        $this->assertFalse($result->sent);
        $this->assertFalse($result->transient);
        $this->assertNotNull($result->error);
    }

    #[DataProvider('transportFailures')]
    public function test_classifies_a_delivery_failure(int $code, bool $expectTransient): void
    {
        $transport = $this->transportThatThrows(new TransportException('rejected', $code));

        $result = $transport->send($this->message(), $this->sender());

        $this->assertFalse($result->sent);
        $this->assertSame($expectTransient, $result->transient);
        $this->assertStringContainsString('rejected', (string) $result->error);
    }

    public static function transportFailures(): array
    {
        return [
            // Greylisting: the receiving MTA rejecting the first attempt on
            // purpose. This is the case the whole retry path exists for.
            '450 greylisted' => [450, true],
            '421 service unavailable' => [421, true],
            // Below SMTP entirely — refused connection, TLS, DNS. Symfony
            // carries no reply code, and these are the most worth retrying.
            'no reply code' => [0, true],
            '550 mailbox unavailable' => [550, false],
            '553 relay denied' => [553, false],
        ];
    }

    public function test_a_transient_failure_is_logged_as_such(): void
    {
        $this->transportThatThrows(new TransportException('greylisted', 450))
            ->send($this->message(), $this->sender());

        $log = implode('', array_map(
            fn ($f) => (string) file_get_contents($f),
            glob($this->logDir . '/*.log') ?: []
        ));

        $this->assertStringContainsString('Mail delivery failed', $log);
        $this->assertStringContainsString('greylisted', $log);
    }

    public function test_describes_where_mail_goes(): void
    {
        $this->assertSame('null to null', $this->transport()->describe());
    }

    private function transport(string $dsn = 'null://null'): SymfonyMailerTransport
    {
        return new SymfonyMailerTransport(MailDsn::parse($dsn), new Logger($this->logDir));
    }

    /**
     * A transport whose underlying Symfony transport throws — the delivery
     * failure path, without a socket. The private property is set directly so
     * production code carries no test seam.
     */
    private function transportThatThrows(\Throwable $e): SymfonyMailerTransport
    {
        $transport = $this->transport();

        $throwing = new class ($e) implements TransportInterface {
            public function __construct(private \Throwable $e) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw $this->e;
            }

            public function __toString(): string
            {
                return 'throwing://test';
            }
        };

        $property = new \ReflectionProperty(SymfonyMailerTransport::class, 'transport');
        $property->setValue($transport, $throwing);

        return $transport;
    }

    private function compose(MailMessage $message, MailSender $sender): Email
    {
        $method = new \ReflectionMethod(SymfonyMailerTransport::class, 'compose');
        return $method->invoke($this->transport(), $message, $sender);
    }

    private function message(): MailMessage
    {
        return new MailMessage(
            to: 'member@example.org',
            subject: 'Vorabankündigung',
            html: '<p>47,50 &euro; am 21.08.2026</p>',
            text: '47,50 EUR am 21.08.2026',
            toName: 'Anna Beispiel',
        );
    }

    private function sender(): MailSender
    {
        return new MailSender('bar@example.org', 'Ruderverein Beispiel', 'kassenwart@example.org');
    }
}
