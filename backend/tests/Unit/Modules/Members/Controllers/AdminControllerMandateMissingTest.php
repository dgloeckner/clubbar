<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Controllers;

use App\Modules\Members\Controllers\AdminController;
use App\Modules\Members\Services\MandateDocumentService;
use App\Modules\Members\Services\MembersService;
use App\Modules\Settlements\DTOs\MandateMissingDto;
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
 * The "no usable mandate" listing under Members (#258).
 *
 * The third standing exclusion, beside credit balances and collection holds,
 * and the one that was least visible before it existed: a member with no
 * mandate can never be collected from, and the only way to notice was reading
 * the SEPA column of the roster row by row.
 */
class AdminControllerMandateMissingTest extends TestCase
{
    private SettlementsService $settlementsService;
    private AdminController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settlementsService = $this->createMock(SettlementsService::class);

        $this->controller = new AdminController(
            $this->createMock(MembersService::class),
            new Validator($this->createMock(\PDO::class)),
            $this->createMock(SepaConfigService::class),
            $this->createMock(MandateDocumentService::class),
            $this->settlementsService,
            $this->createMock(CollectionHoldService::class),
        );
    }

    public function test_the_listing_answers_with_every_member_and_what_cannot_be_collected(): void
    {
        $this->settlementsService->method('listMembersWithoutMandate')->willReturn([
            'items' => [$this->entry('member-1', 1250), $this->entry('member-2', 620)],
            'total_uncollectable_cents' => 1870,
        ]);

        $response = $this->controller->mandateMissing(
            $this->request('GET', '/api/admin/members/mandate-missing'),
            new Response(),
        );
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $body['items']);
        $this->assertSame('member-1', $body['items'][0]['member_id']);
        $this->assertSame(1250, $body['items'][0]['balance_cents']);
        // Money owed to the club that no run can reach — positive, and a
        // different figure from the credit and held totals beside it.
        $this->assertSame(1870, $body['total_uncollectable_cents']);
    }

    public function test_an_empty_listing_is_an_empty_list_not_a_404(): void
    {
        $this->settlementsService->method('listMembersWithoutMandate')->willReturn([
            'items' => [],
            'total_uncollectable_cents' => 0,
        ]);

        $response = $this->controller->mandateMissing(
            $this->request('GET', '/api/admin/members/mandate-missing'),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->decode($response)['items']);
        $this->assertSame(0, $this->decode($response)['total_uncollectable_cents']);
    }

    private function entry(string $memberId, int $balanceCents): MandateMissingDto
    {
        return new MandateMissingDto(
            memberId: $memberId,
            firstName: 'Jonas',
            lastName: 'Feld',
            balanceCents: $balanceCents,
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
