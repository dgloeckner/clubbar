<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Controllers;

use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\NotificationsService;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The mail queue, as the Kassenwart browses it (#407).
 *
 * ADR-0038 rule 6 makes delivery best effort *and never invisible*; this is the
 * surface that keeps the second half of that promise. It is deliberately a
 * queue view rather than a per-settlement one: the Deckelauszug (#462) has no
 * settlement to appear under, and a page keyed on settlements would make an
 * entire message kind invisible by construction.
 *
 * ### What this controller must never grow
 *
 * ADR-0038 rule 4: **the UI may change state, it may never orchestrate time.**
 * `retry()` sets one row back to `pending` and stops — that is a state change.
 * What does not belong here, in any form: an endpoint that drains, an endpoint
 * that reports progress for a client to poll, or anything that would let a
 * browser tab become a second sending path. There is one sender.
 */
class NotificationsController
{
    use JsonResponder;

    public function __construct(
        private NotificationsService $notificationsService,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = ListQuery::fromParams($params, defaultSortKey: 'queued_at', defaultSortOrder: 'desc');

        // An unknown kind or status is refused rather than ignored. Silently
        // dropping the filter would answer a question nobody asked — the whole
        // queue, presented as if it were the failures — and the caller has no
        // way to tell that from an empty result.
        foreach (['kind' => MailKind::class, 'status' => MailStatus::class] as $key => $enum) {
            $value = $params[$key] ?? null;
            if (is_string($value) && $value !== '' && $enum::tryFrom($value) === null) {
                return $this->validationFailed($response, [
                    $key => sprintf(
                        '%s must be one of: %s',
                        $key,
                        implode(', ', array_column($enum::cases(), 'value'))
                    ),
                ]);
            }
        }

        $result = $this->notificationsService->search([
            'kind' => $params['kind'] ?? null,
            'status' => $params['status'] ?? null,
            'subject_id' => $params['subject_id'] ?? null,
            'search' => $query->search,
        ], $query);

        return $this->json($response, PaginatedResponse::fromQuery($result['items'], $result['total'], $query) + [
            // The vocabulary the filter controls, so the page's dropdowns come
            // from the server that enforces them rather than from a copy in the
            // frontend that can drift the day a kind is added.
            'filters' => [
                'kinds' => array_column(MailKind::cases(), 'value'),
                'statuses' => array_column(MailStatus::cases(), 'value'),
                'sortable' => array_keys(MailOutboxRepository::SORTABLE),
            ],
        ]);
    }

    /**
     * Put one failed message back in the queue.
     *
     * 409 rather than 404 when the row exists but is not retryable: nothing
     * about the request is malformed, and the reason — it was already sent, or
     * the settlement behind it was cancelled — is what the admin needs to read.
     */
    public function retry(Request $request, Response $response, array $args): Response
    {
        $id = (string) ($args['id'] ?? '');
        $adminId = (string) $request->getAttribute('admin_user_id');

        $existing = $this->notificationsService->find($id);
        if ($existing === null) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'No such queued message'], 404);
        }

        $retried = $this->notificationsService->retry($id, $adminId);
        if ($retried === null) {
            return $this->json($response, [
                'error' => 'not_retryable',
                'message' => sprintf(
                    'A message with status "%s" cannot be retried. Only a failed message can go back in the queue: '
                    . 'a sent one has already reached somebody, and a superseded one belongs to a collection that '
                    . 'was called off.',
                    $existing->status->value
                ),
            ], 409);
        }

        return $this->json($response, $retried->toArray());
    }
}
