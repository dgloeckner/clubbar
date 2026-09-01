<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Controllers;

use App\Modules\AdminUsers\Controllers\InvitationController;
use App\Modules\AdminUsers\Services\AdminInvitationService;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Shared\Config\AppConfig;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Validation\Validator;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Accepting an invitation ends the session the browser arrived with (#798).
 *
 * The bug this pins: an invitee follows their link on a machine somebody else
 * is already signed in to — the club laptop is the ordinary case, not the
 * exotic one — sets their password, and is sent to `/login`, which redirects an
 * authenticated browser to the dashboard. They landed inside the *other*
 * admin's account having proven nothing but that they can read an email.
 *
 * Asserted here rather than only in the E2E suite because the guarantee is the
 * endpoint's, not the panel's: any client that sets a password through a link
 * loses whatever identity it was carrying.
 */
class InvitationControllerSessionTest extends TestCase
{
    private const PASSWORD = 'Str0ngPassword';

    private AdminInvitationService $invitationService;
    private LoginAttemptsRepository $loginAttempts;
    private InvitationController $controller;
    private string $cookieName;

    protected function setUp(): void
    {
        $this->invitationService = $this->createMock(AdminInvitationService::class);
        $this->loginAttempts = $this->createMock(LoginAttemptsRepository::class);

        $config = new AppConfig();
        $this->cookieName = $config->sessionCookieName;

        $this->controller = new InvitationController(
            $this->invitationService,
            new Validator($this->createMock(PDO::class)),
            $this->loginAttempts,
            $config,
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_COOKIE[$this->cookieName]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** Somebody else's live admin session, exactly as the browser presents it. */
    private function signInSomebodyElse(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = ['admin_user_id' => 'the-inviting-admin', 'csrf_token' => 'irrelevant'];
        $_COOKIE[$this->cookieName] = session_id();
    }

    private function post(string $path, array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path, ['REMOTE_ADDR' => '203.0.113.7'])
            ->withParsedBody($body);
    }

    private function acceptBody(): array
    {
        return [
            'token' => str_repeat('a', 64),
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }

    public function test_accepting_ends_the_session_the_browser_arrived_with(): void
    {
        $this->signInSomebodyElse();
        $this->invitationService->method('accept')->willReturn(['email' => 'invitee@example.com']);

        $response = $this->controller->accept($this->post('/api/invitations/accept', $this->acceptBody()), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'The session survived the acceptance');
        $this->assertSame([], $_SESSION);
    }

    /**
     * No session, no session file. Starting one here to destroy it would create
     * the very thing the method exists to remove — and would hand a browser
     * that had no cookie a brand-new one.
     */
    public function test_accepting_without_a_session_starts_none(): void
    {
        $this->invitationService->method('accept')->willReturn(['email' => 'invitee@example.com']);

        $response = $this->controller->accept($this->post('/api/invitations/accept', $this->acceptBody()), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
    }

    /**
     * A refused link changes nothing — including whose browser this is. A spent
     * or expired token must not be a way to sign an admin out.
     */
    public function test_a_refused_link_leaves_the_session_alone(): void
    {
        $this->signInSomebodyElse();
        $this->invitationService->method('accept')->willThrowException(
            new BusinessRuleException(BusinessRuleReason::INVITATION_INVALID, 'This invitation link is not valid'),
        );

        try {
            $this->controller->accept($this->post('/api/invitations/accept', $this->acceptBody()), new Response());
            $this->fail('An invalid link should have been refused');
        } catch (BusinessRuleException) {
            // The refusal is the exception handler's to render; what matters here
            // is what it did not do on the way out.
        }

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame('the-inviting-admin', $_SESSION['admin_user_id']);
    }

    /**
     * A password that fails the rules is the invitee mistyping, not an
     * acceptance — nothing has been set, so nothing has changed hands.
     */
    public function test_a_rejected_password_leaves_the_session_alone(): void
    {
        $this->signInSomebodyElse();
        $this->invitationService->expects($this->never())->method('accept');

        $response = $this->controller->accept(
            $this->post('/api/invitations/accept', ['token' => str_repeat('a', 64), 'password' => 'short', 'password_confirmation' => 'short']),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame('the-inviting-admin', $_SESSION['admin_user_id']);
    }

    /**
     * Reading the page is not accepting. An admin who opens a link they issued
     * — to check it arrived, or to help a colleague through it — stays signed
     * in; only setting a password hands the browser a new identity.
     */
    public function test_looking_a_link_up_leaves_the_session_alone(): void
    {
        $this->signInSomebodyElse();
        $this->invitationService->method('describe')->willReturn([
            'email' => 'invitee@example.com',
            'display_name' => 'Invited Colleague',
            'locale' => 'de',
            'roles' => [],
        ]);

        $response = $this->controller->lookup(
            $this->post('/api/invitations/lookup', ['token' => str_repeat('a', 64)]),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame('the-inviting-admin', $_SESSION['admin_user_id']);
    }
}
