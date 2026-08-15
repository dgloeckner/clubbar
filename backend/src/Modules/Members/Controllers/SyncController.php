<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Modules\Members\Services\MembersService;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Modules\Terminals\Enums\SyncStream;
use App\Modules\Terminals\Services\TerminalSyncCursorService;
use App\Shared\Http\JsonResponder;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SyncController
{
    use JsonResponder;

    public function __construct(
        private MembersService $membersService,
        private Validator $validator,
        private TerminalSyncCursorService $syncCursors,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        // Client sends milliseconds timestamp
        $since = isset($params['since']) ? (int) $params['since'] : 0;

        // ADR-0041: the presented cursor exists only here, so this is where it
        // is compared against what this terminal has previously been given. It
        // does not change the delta below in any way.
        $this->syncCursors->observe($request->getAttribute('terminal_id'), SyncStream::MEMBERS, $since);

        $result = $this->membersService->syncSince($since);

        return $this->json($response, $result->toArray('members'));
    }

    /**
     * `preferred_language` is a named field examined and rejected, so this
     * follows the same `Validator` -> 422 `validation_failed` convention as
     * every admin endpoint (#446) rather than the hand-rolled 400 it used to
     * return. The Flutter terminal client does not branch on the status code
     * for this call — any non-2xx is treated the same — so this is safe to
     * change without a client update.
     */
    public function updateLanguage(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'preferred_language' => ['required', 'in:de,en,fr'],
        ])) {
            $errors = $this->validator->errors();

            // The terminal API's shared ErrorResponse requires `message`
            // (every other terminal error carries one); the admin API's
            // Validator sites never have and don't need to follow suit.
            return $this->validationFailed($response, $errors, $errors['preferred_language'][0]);
        }

        $language = SupportedLanguage::from($body['preferred_language']);
        $member = $this->membersService->updateLanguage($memberId, $language);

        return $this->json($response, $member->toArray());
    }
}
