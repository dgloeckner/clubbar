<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Mail;

use App\Shared\Mail\MailMessage;
use PHPUnit\Framework\TestCase;

class MailMessageTest extends TestCase
{
    public function test_accepts_a_complete_multipart_message(): void
    {
        $message = new MailMessage(
            to: 'member@example.org',
            subject: 'Vorabankündigung',
            html: '<p>47,50 € am 21.08.2026</p>',
            text: '47,50 EUR am 21.08.2026',
            toName: 'Anna Beispiel',
        );

        $this->assertSame('member@example.org', $message->to);
        $this->assertSame('Anna Beispiel', $message->toName);
        $this->assertSame([], $message->embeddedImages);
    }

    public function test_refuses_a_message_without_a_text_part(): void
    {
        // Multipart is mandatory. Enforcing it in the constructor means a
        // template author cannot ship an HTML-only mail and find out in review.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/multipart is mandatory/');

        new MailMessage(
            to: 'member@example.org',
            subject: 'Vorabankündigung',
            html: '<p>47,50 €</p>',
            text: '   ',
        );
    }

    public function test_refuses_a_message_without_an_html_part(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MailMessage(to: 'member@example.org', subject: 'Betreff', html: '', text: 'text');
    }

    public function test_refuses_a_message_with_no_recipient(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MailMessage(to: '', subject: 'Betreff', html: '<p>x</p>', text: 'x');
    }

    public function test_refuses_a_message_with_no_subject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MailMessage(to: 'member@example.org', subject: '', html: '<p>x</p>', text: 'x');
    }
}
