<?php

declare(strict_types=1);

namespace App\Modules\Auth\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Modules\Terminals\Repositories\TerminalIpSightingsRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Services\TerminalTokenAuthenticator;
use App\Shared\Logging\Logger;
use App\Shared\Version\ReleaseVersion;
use Slim\Psr7\Response;

class TerminalTokenAuth implements MiddlewareInterface
{
    /**
     * What the terminal is running (ADR-0054). A header rather than a field in
     * the sync payload: a terminal that syncs but books nothing still reports,
     * and the version belongs to the *terminal*, not to a batch of
     * transactions.
     */
    public const VERSION_HEADER = 'X-Terminal-Version';

    /**
     * The tag whose update failed on this terminal, if any. Exact-match plus a
     * blacklist means such a terminal will never move again on its own, so this
     * is the only way the club can find out.
     */
    public const BLOCKED_VERSION_HEADER = 'X-Terminal-Blocked-Version';

    /**
     * @param LoginAttemptsRepository $authAttempts scoped to `terminal_auth_attempts`,
     *        which has no `email` column — attempts are recorded by IP alone.
     */
    public function __construct(
        private TerminalsRepository $terminalsRepository,
        private LoginAttemptsRepository $authAttempts,
        private TerminalTokenAuthenticator $authenticator,
        private TerminalIpSightingsRepository $ipSightings,
        private Logger $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (empty($authHeader)) {
            return $this->unauthorized($request, 'authorization_header_missing', 'Authorization header required');
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorized($request, 'invalid_authorization_format', 'Expected Bearer token');
        }

        $token = substr($authHeader, 7);
        // The authenticator, not this middleware, decides what a token means:
        // it also promotes a pending token on first use and records an expiry
        // once (#395). Everything below is HTTP.
        $result = $this->authenticator->authenticate($token);
        $terminal = $result->terminal;

        if (!$terminal) {
            // A token that was valid until its lifetime ran out gets its own
            // answer (#106): the terminal is not misconfigured, it needs an
            // admin to rotate it. Nothing is disclosed to a caller that does
            // not already hold the token.
            if ($result->isExpired()) {
                return $this->unauthorized(
                    $request,
                    'terminal_token_expired',
                    'Terminal token has expired. Rotate the token in the admin panel to issue a new one.'
                );
            }

            return $this->unauthorized($request, 'invalid_terminal_token', 'Invalid terminal token');
        }

        if (!(bool) $terminal['is_active']) {
            return $this->unauthorized($request, 'terminal_inactive', 'Terminal is inactive');
        }

        // Update last sync timestamp, and record what the terminal says it is
        // running (ADR-0054). Fail-open: an unparseable or absent header leaves
        // the version columns untouched instead of refusing the sync.
        $this->terminalsRepository->updateLastSync(
            $terminal['id'],
            self::releaseTagHeader($request, self::VERSION_HEADER),
            self::releaseTagHeader($request, self::BLOCKED_VERSION_HEADER),
        );

        // ADR-0041: record where this request came from, so the cron tick can
        // ask whether two devices are holding one token. Observation only —
        // nothing below branches on it, and a failure here must not cost a
        // sale, which is why it cannot escape as an exception.
        try {
            $this->ipSightings->record(
                $terminal['id'],
                $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1',
                time(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Could not record terminal IP sighting', [
                'terminal_id' => $terminal['id'],
                'error' => $e->getMessage(),
            ]);
        }

        $request = $request->withAttribute('terminal_id', $terminal['id']);
        $request = $request->withAttribute('terminal', $terminal);

        return $handler->handle($request)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * The named header, if it holds a release tag — null for anything else.
     *
     * "Anything else" deliberately covers a great deal: a missing header, an
     * empty one, `dev`, `dev-<sha>`, and a value some middlebox rewrote. None
     * of them is a version, and none of them is a reason to refuse a sale, so
     * all of them read as "this terminal reported nothing".
     */
    private static function releaseTagHeader(ServerRequestInterface $request, string $name): ?string
    {
        $value = trim($request->getHeaderLine($name));

        return ReleaseVersion::isReleaseTag($value) ? $value : null;
    }

    private function unauthorized(ServerRequestInterface $request, string $code, string $message): ResponseInterface
    {
        $this->authAttempts->record($request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1');

        $response = new Response(401);
        $response->getBody()->write(json_encode(['error' => $code, 'message' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
