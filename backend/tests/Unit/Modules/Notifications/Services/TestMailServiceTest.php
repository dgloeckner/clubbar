<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\TestMailService;
use App\Shared\Enums\AuditAction;
use App\Shared\Mail\InvalidMailDsnException;
use App\Shared\Mail\MailDsn;
use App\Shared\Mail\MailMessage;
use App\Shared\Mail\MailSender;
use App\Shared\Mail\MailSendResult;
use App\Shared\Mail\MailTransport;
use App\Shared\Mail\MailTransportFactory;
use App\Shared\Mail\MailTransportStatus;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * The one diagnostic that opens a socket from a web request (#407).
 *
 * Two of these tests are the reason it is allowed to exist at all, and they are
 * the ones to break if somebody makes this endpoint friendlier: the recipient
 * comes from the session and never from the caller, and the content is fixed.
 * Without both, "send a test mail" is an authenticated open relay on the club's
 * own domain.
 *
 * No socket is opened here either — the transport is a double that records.
 */
class TestMailServiceTest extends TestCase
{
    private const ADMIN_ID = '11111111-1111-4111-8111-111111111111';
    private const ADMIN_EMAIL = 'kassenwart@example.org';

    private RecordingTransport $transport;
    private AuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new RecordingTransport();
        $this->audit = $this->createMock(AuditService::class);
    }

    private function service(
        ?string $adminEmail = self::ADMIN_EMAIL,
        string $senderAddress = 'bar@example.org',
        ?MailTransportStatus $status = null,
        bool $dsnThrows = false,
    ): TestMailService {
        $mailConfig = $this->createMock(MailConfigService::class);
        $mailConfig->method('getConfig')->willReturn(new MailConfigDto(
            senderName: 'Beispielverein',
            senderAddress: $senderAddress,
            replyToAddress: null,
            headerStyle: 'paper',
            footerOrgName: 'Beispielverein e. V.',
            footerAddressLine: null,
            websiteUrl: null,
            logoUrl: null,
        ));

        $factory = $this->createMock(MailTransportFactory::class);
        $factory->method('status')->willReturn(
            $status ?? MailTransportStatus::ok(MailDsn::parse('smtp://user:pass@mail.example.org:587'))
        );
        if ($dsnThrows) {
            $factory->method('create')->willThrowException(new InvalidMailDsnException('unsupported scheme'));
        } else {
            $factory->method('create')->willReturn($this->transport);
        }

        $admins = $this->createMock(AdminUsersRepository::class);
        $admins->method('findById')->willReturn(
            $adminEmail === null ? null : ['id' => self::ADMIN_ID, 'email' => $adminEmail]
        );

        return new TestMailService($mailConfig, $factory, $admins, $this->audit);
    }

    /**
     * The boundary that keeps this from being an open relay: the address is
     * read from the admin record the session names, and there is no parameter
     * to point it anywhere else.
     */
    public function test_it_sends_only_to_the_requesting_admins_own_address(): void
    {
        $result = $this->service()->sendTo(self::ADMIN_ID);

        $this->assertTrue($result['sent']);
        $this->assertSame(self::ADMIN_EMAIL, $result['recipient']);
        $this->assertCount(1, $this->transport->sent);
        $this->assertSame(self::ADMIN_EMAIL, $this->transport->sent[0]->to);
    }

    /**
     * The second boundary: nothing about a member, a settlement or a request
     * body reaches the message, so it cannot be turned into a mail to somebody
     * about something.
     */
    public function test_the_content_is_fixed_and_mentions_no_member_or_amount(): void
    {
        $this->service()->sendTo(self::ADMIN_ID);
        $message = $this->transport->sent[0];

        $this->assertStringContainsString('Test', $message->subject);
        $this->assertMatchesRegularExpression('/Mailversand funktioniert/', $message->html);
        // Multipart is mandatory and the text part says the same thing (#401).
        $this->assertStringContainsString('Der Mailversand funktioniert', $message->text);
        $this->assertDoesNotMatchRegularExpression('/\d+,\d\d\s*€/', $message->html, 'a test mail carries no amount');
        $this->assertStringNotContainsString('Mandatsreferenz', $message->html);
    }

    public function test_it_names_the_transport_it_used(): void
    {
        $result = $this->service()->sendTo(self::ADMIN_ID);

        $this->assertSame($this->transport->describe(), $result['transport']);
    }

    /**
     * The whole value of the button is the server's own words. "Authentication
     * failed", "connection refused" and "relay access denied" point at three
     * different fixes, and a friendly summary would erase the difference.
     */
    public function test_a_refusal_comes_back_verbatim_rather_than_as_an_exception(): void
    {
        $this->transport->result = MailSendResult::permanentFailure('535 5.7.8 Authentication credentials invalid');

        $result = $this->service()->sendTo(self::ADMIN_ID);

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('535 5.7.8', (string) $result['error']);
    }

    public function test_an_admin_without_an_address_is_told_so_rather_than_failing(): void
    {
        $result = $this->service(adminEmail: '')->sendTo(self::ADMIN_ID);

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('no email address', (string) $result['error']);
        $this->assertSame([], $this->transport->sent, 'nothing is attempted without a recipient');
    }

    public function test_an_unconfigured_sender_is_reported_before_anything_is_attempted(): void
    {
        $result = $this->service(senderAddress: '')->sendTo(self::ADMIN_ID);

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('sender address', (string) $result['error']);
        $this->assertSame([], $this->transport->sent);
    }

    public function test_a_missing_transport_names_where_the_dsn_lives(): void
    {
        $result = $this->service(status: MailTransportStatus::unconfigured())->sendTo(self::ADMIN_ID);

        $this->assertFalse($result['sent']);
        // The remedy is a file operation, and an admin who cannot find it will
        // otherwise look for it in this very form.
        $this->assertStringContainsString('config.php', (string) $result['error']);
    }

    public function test_an_unusable_dsn_is_reported_rather_than_thrown(): void
    {
        $result = $this->service(dsnThrows: true)->sendTo(self::ADMIN_ID);

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('unsupported scheme', (string) $result['error']);
    }

    /**
     * Audited because this is the single place that sends outside the
     * scheduler. The entry is what makes "it only ever goes to the admin's own
     * address" checkable after the fact rather than merely asserted here.
     */
    public function test_a_send_is_audited_with_no_address_in_the_values(): void
    {
        $this->audit->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::MAIL_TEST_SENT,
                $this->anything(),
                '1',
                null,
                $this->callback(static function (array $values): bool {
                    return $values['sent'] === true
                        && !str_contains(json_encode($values) ?: '', '@');
                }),
                self::ADMIN_ID,
            );

        $this->service()->sendTo(self::ADMIN_ID);
    }

    /** A failure that never reached the transport is not worth an audit entry. */
    public function test_nothing_is_audited_when_there_was_nothing_to_try(): void
    {
        $this->audit->expects($this->never())->method('log');

        $this->service(status: MailTransportStatus::unconfigured())->sendTo(self::ADMIN_ID);
    }
}

/** A transport that records instead of connecting. */
final class RecordingTransport implements MailTransport
{
    /** @var list<MailMessage> */
    public array $sent = [];

    public MailSendResult $result;

    public function __construct()
    {
        $this->result = MailSendResult::sent('<test@example.org>');
    }

    public function send(MailMessage $message, MailSender $sender): MailSendResult
    {
        $this->sent[] = $message;

        return $this->result;
    }

    public function describe(): string
    {
        return 'smtp to mail.example.org:587';
    }
}
