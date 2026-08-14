<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Controllers;

use App\Modules\Members\Controllers\AdminController;
use App\Modules\Members\Services\MembersService;
use App\Modules\Settlements\DTOs\CollectionHoldDto;
use App\Modules\Settlements\Services\CollectionHoldService;
use App\Modules\Settlements\Services\SepaConfigService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The two collection-hold endpoints under Members (#196, ruling #148 §4-§5).
 *
 * They sit beside credit balances rather than under settlements for the same
 * reason that listing does: a hold is a standing fact about a member, and an
 * exclusion nobody can see is an exclusion nobody ever resolves.
 */
class AdminControllerCollectionHoldsTest extends TestCase
{
    private CollectionHoldService $collectionHoldService;
    private AdminController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collectionHoldService = $this->createMock(CollectionHoldService::class);

        $this->controller = new AdminController(
            $this->createMock(MembersService::class),
            new Validator($this->createMock(\PDO::class)),
            $this->createMock(SepaConfigService::class),
            $this->createMock(SettlementsService::class),
            $this->collectionHoldService,
        );
    }

    public function test_the_listing_answers_with_every_hold_and_the_total_held(): void
    {
        $this->collectionHoldService->method('listHeld')->willReturn([
            'items' => [$this->hold('member-1'), $this->hold('member-2')],
            'total_held_cents' => 4000,
        ]);

        $body = $this->decode($this->controller->collectionHolds(
            $this->request('GET', '/api/admin/members/collection-holds'),
            new Response(),
        ));

        $this->assertCount(2, $body['items']);
        $this->assertSame('member-1', $body['items'][0]['member_id']);
        $this->assertSame('Returned unpaid', $body['items'][0]['collection_hold_reason']);
        $this->assertSame(4000, $body['total_held_cents']);
    }

    public function test_an_empty_listing_is_an_empty_list_not_a_404(): void
    {
        $this->collectionHoldService->method('listHeld')->willReturn(['items' => [], 'total_held_cents' => 0]);

        $response = $this->controller->collectionHolds(
            $this->request('GET', '/api/admin/members/collection-holds'),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->decode($response)['items']);
    }

    public function test_clearing_a_hold_names_the_member_and_the_admin_doing_it(): void
    {
        $this->collectionHoldService->expects($this->once())
            ->method('clearHold')
            ->with('member-1', 'admin-1')
            ->willReturn($this->hold('member-1'));

        $response = $this->controller->clearCollectionHold(
            $this->request('POST', '/api/admin/members/member-1/collection-hold/clear'),
            new Response(),
            ['memberId' => 'member-1'],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('member-1', $this->decode($response)['member_id']);
    }

    private function hold(string $memberId): CollectionHoldDto
    {
        return new CollectionHoldDto(
            memberId: $memberId,
            firstName: 'Alice',
            lastName: 'Member',
            reason: 'Returned unpaid',
            heldAt: '2026-08-08 10:00:00',
            heldByAdminId: 'admin-1',
            heldByAdminName: 'The Treasurer',
            balanceCents: 2000,
        );
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute('admin_user_id', 'admin-1');
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }
}
