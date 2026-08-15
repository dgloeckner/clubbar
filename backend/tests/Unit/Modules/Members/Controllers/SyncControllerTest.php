<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Controllers;

use App\Modules\Members\Controllers\SyncController;
use App\Modules\Members\Services\MembersService;
use App\Modules\Terminals\Services\TerminalSyncCursorService;
use App\Shared\Validation\Validator;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * PATCH /api/sync/members/{memberId}/language — moved onto `Validator` in
 * #446, so a rejected field now answers 422 `validation_failed` like every
 * admin endpoint, with the terminal API's required `message` alongside it.
 */
class SyncControllerTest extends TestCase
{
    private MembersService $membersService;
    private SyncController $controller;

    protected function setUp(): void
    {
        $this->membersService = $this->createMock(MembersService::class);
        $this->controller = new SyncController(
            $this->membersService,
            new Validator($this->createMock(PDO::class)),
            $this->createMock(TerminalSyncCursorService::class),
        );
    }

    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    public function test_rejects_a_missing_preferred_language(): void
    {
        $this->membersService->expects($this->never())->method('updateLanguage');

        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/sync/members/member-1/language')
            ->withParsedBody([]);

        $response = $this->controller->updateLanguage($request, new Response(), ['memberId' => 'member-1']);

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('preferred_language', $body['messages']);
        // The terminal API's shared ErrorResponse requires a top-level `message`.
        $this->assertSame($body['messages']['preferred_language'][0], $body['message']);
    }

    public function test_rejects_a_language_not_in_the_supported_set(): void
    {
        $this->membersService->expects($this->never())->method('updateLanguage');

        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/sync/members/member-1/language')
            ->withParsedBody(['preferred_language' => 'xx']);

        $response = $this->controller->updateLanguage($request, new Response(), ['memberId' => 'member-1']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('preferred_language', $this->decode($response)['messages']);
    }
}
