<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MembersService;
use App\Enum\SupportedLanguage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MembersSyncController
{
    public function __construct(
        private MembersService $membersService,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $since = isset($params['since']) ? (int) $params['since'] : 0;

        $result = $this->membersService->syncSince($since);

        return $this->json($response, $result->toArray());
    }

    public function updateLanguage(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $body = $request->getParsedBody() ?? [];

        $langValue = $body['preferred_language'] ?? null;
        if (!$langValue) {
            return $this->json($response, ['error' => 'preferred_language is required'], 422);
        }

        $language = SupportedLanguage::tryFrom($langValue);
        if (!$language) {
            return $this->json($response, ['error' => 'Invalid language. Supported: de, en, fr'], 422);
        }

        $member = $this->membersService->updateLanguage($memberId, $language);

        return $this->json($response, $member->toArray());
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
