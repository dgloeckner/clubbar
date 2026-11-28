<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Members\DTOs\OpenMandatePrintDto;
use App\Modules\Members\Services\OpenMandatePrintService;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/members/mandate-documents — every open mandate as one PDF
 * (UC-A18).
 *
 * Pattern 006 thin. What it decides is the shape of the request — and with it
 * which of the two prints was asked for:
 *
 * - **no `private_key`** — the blank layout. Nothing here needs a secret, so
 *   nothing here asks for a credential;
 * - **`private_key`** — the full IBAN. From that point this is
 *   {@see \App\Modules\Settlements\Controllers\AdminController::exportSepa()}
 *   step for step, on purpose: the same secret travelling the same way has to
 *   meet the same checks, and a second, slightly different way to hand over
 *   the club's key would be a second thing to get wrong.
 *
 * A POST although it changes nothing, because the keyed variant carries the
 * key in its body and a GET has nowhere to put one. In the JSON body, never
 * multipart: PHP writes multipart parts to temporary files on disk, which is
 * exactly the persistence ADR-0036 exists to avoid.
 */
class MandateDocumentsController
{
    use JsonResponder;

    /** A member id is a UUID; anything else in the list is a malformed request. */
    private const ID_SHAPE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** More than a club prints in an evening, and few enough to render in one request. */
    public const MEMBER_LIMIT = 500;

    public function __construct(
        private OpenMandatePrintService $mandates,
        private EncryptionKeyService $encryptionKeyService,
        private StepUpAuthService $stepUpAuthService,
    ) {}

    public function print(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        $memberIds = $body['member_ids'] ?? null;
        if ($memberIds !== null) {
            $problem = match (true) {
                !is_array($memberIds) || !array_is_list($memberIds) || $memberIds === []
                    => 'member_ids must be a non-empty list of member ids, or be left out',
                count($memberIds) > self::MEMBER_LIMIT
                    => sprintf('member_ids may name at most %d members', self::MEMBER_LIMIT),
                array_filter(
                    $memberIds,
                    static fn($id): bool => !is_string($id) || preg_match(self::ID_SHAPE, $id) !== 1,
                ) !== [] => 'every entry of member_ids must be a member id',
                default => null,
            };

            if ($problem !== null) {
                return $this->validationFailed($response, ['member_ids' => [$problem]]);
            }

            $memberIds = array_values(array_unique($memberIds));
        }

        $privateKey = $body['private_key'] ?? null;

        // An empty key is not "the blank print, please". Somebody chose the
        // full-IBAN variant and the key did not arrive; answering with a blank
        // document would look like the request had worked.
        if ($privateKey !== null && (!is_string($privateKey) || trim($privateKey) === '')) {
            return $this->validationFailed($response, ['private_key' => ['private_key must be a non-empty string']]);
        }

        if ($privateKey === null) {
            return $this->pdf($response, $this->mandates->render($memberIds, null, $adminId));
        }

        $caller = $request->getAttribute('admin_user');
        if ($caller === null || !$this->stepUpAuthService->verify($caller, $body, $request)) {
            return $this->json($response, [
                'error' => 'invalid_credentials',
                'message' => 'Re-enter your password (and TOTP code) to print mandates with the full IBAN',
            ], 401);
        }

        try {
            $result = $this->encryptionKeyService->withActivePrivateKey(
                $privateKey,
                fn(callable $openIban) => $this->mandates->render($memberIds, $openIban, $adminId),
            );
        } catch (\InvalidArgumentException $e) {
            // Wrong key for this club: nothing was decrypted, so this is a bad
            // input rather than a conflict with server state.
            return $this->json($response, ['error' => 'private_key_mismatch', 'message' => $e->getMessage()], 422);
        }

        return $this->pdf($response, $result);
    }

    private function pdf(Response $response, OpenMandatePrintDto $result): Response
    {
        $response->getBody()->write($result->pdf);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="offene-mandate-' . date('Y-m-d') . '.pdf"',
            )
            // Names, birth dates, addresses — and on the keyed print, IBANs in
            // the clear. It must not sit in a proxy or a browser cache on a
            // shared clubhouse machine once the download is done (ADR-0036).
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache')
            // The body is the document and cannot carry a remark, so the two
            // numbers ride beside it, as the SEPA export's omissions do (#114).
            ->withHeader('X-Mandate-Sheets', (string) $result->sheetCount)
            ->withHeader('X-Mandate-Sheets-Without-Iban', (string) $result->withoutIbanCount)
            ->withStatus(200);
    }
}
