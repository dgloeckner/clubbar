<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Controllers;

use App\Modules\Members\Controllers\AdminController;
use App\Modules\Members\Services\MembersService;
use App\Modules\Settlements\Services\CollectionHoldService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The Datenqualität panel's figures (#629).
 *
 * The roster could always filter for a gap. What it could not do is say how
 * many members had one — so four capable filter pills answered a question
 * nobody knew to ask. This endpoint is the question.
 */
class AdminControllerCompletenessTest extends TestCase
{
    private MembersService $membersService;
    private AdminController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->membersService = $this->createMock(MembersService::class);

        $this->controller = new AdminController(
            $this->membersService,
            new Validator($this->createMock(\PDO::class)),
            $this->createMock(SettlementsService::class),
            $this->createMock(CollectionHoldService::class),
        );
    }

    public function test_it_answers_with_a_count_per_gap(): void
    {
        $this->membersService->method('getDataCompleteness')->willReturn([
            'total' => 132,
            'without_card_uid' => 9,
            'without_email' => 2,
            'without_date_of_birth' => 3,
            'without_mandate' => 4,
            'incomplete' => 14,
        ]);

        $response = $this->controller->completeness($this->request(), new Response());
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(132, $body['total']);
        $this->assertSame(9, $body['without_card_uid']);
        $this->assertSame(2, $body['without_email']);
        $this->assertSame(3, $body['without_date_of_birth']);
        $this->assertSame(4, $body['without_mandate']);
        // Not the sum of the four: one member can be missing several, and a
        // panel whose headline double-counts them is a panel nobody believes.
        $this->assertSame(14, $body['incomplete']);
        $this->assertLessThanOrEqual(
            $body['without_card_uid'] + $body['without_email']
                + $body['without_date_of_birth'] + $body['without_mandate'],
            $body['incomplete'],
        );
    }

    public function test_a_club_with_nothing_missing_gets_zeros_rather_than_an_empty_body(): void
    {
        $this->membersService->method('getDataCompleteness')->willReturn([
            'total' => 12,
            'without_card_uid' => 0,
            'without_email' => 0,
            'without_date_of_birth' => 0,
            'without_mandate' => 0,
            'incomplete' => 0,
        ]);

        $response = $this->controller->completeness($this->request(), new Response());
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        // The panel renders its "all clear" line off `incomplete === 0`, so a
        // missing key would read as "not known" and hide the panel entirely.
        $this->assertSame(0, $body['incomplete']);
        $this->assertSame(12, $body['total']);
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/admin/members/completeness')
            ->withAttribute('admin_user_id', 'admin-1');
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }
}
