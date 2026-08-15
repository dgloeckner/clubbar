<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Controllers;

use App\Modules\Settlements\Services\SepaConfigService;
use App\Shared\Validation\Validator;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SepaConfigController
{
    use JsonResponder;

    public function __construct(
        private SepaConfigService $sepaConfigService,
        private Validator $validator,
    ) {}

    /**
     * The creditor IBAN is the club's own account, but it is still a bank
     * account number on a screen an admin session can reach, and the OAS has
     * always specced this response as masked. It was returned in full anyway,
     * which made the settings form the one place a full IBAN still leaked out
     * of the API (#392).
     */
    public function show(Request $request, Response $response): Response
    {
        $config = $this->sepaConfigService->getConfig(masked: true);

        if (!$config) {
            return $this->json($response, ['error' => 'SEPA configuration not found'], 404);
        }

        return $this->json($response, $config->toArray());
    }

    public function update(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        // Overwrite-only, the same contract the member form follows: the client
        // never receives the stored IBAN, so it cannot send it back unchanged.
        // An omitted or blank field therefore means "keep what is stored", and
        // only a filled one is validated and written. Without this the masked
        // GET would make every save that did not retype the IBAN wipe it.
        $stored = $this->sepaConfigService->getConfig(masked: false);
        $submittedIban = $body['creditor_iban'] ?? null;
        $keepsStoredIban = ($submittedIban === null || $submittedIban === '')
            && !empty($stored?->creditorIban);

        if ($keepsStoredIban) {
            unset($body['creditor_iban']);
        } elseif (is_string($submittedIban) && str_contains($submittedIban, '*')) {
            // The masked value echoed back from the form. Caught by name rather
            // than left to the checksum rule, which would report this as a
            // malformed IBAN and send the admin looking for a typo.
            return $this->validationFailed($response, ['creditor_iban' => ['Leave the field empty to keep the stored IBAN, or enter the full IBAN to replace it.']]);
        }

        $rules = [
            'creditor_name' => ['required', 'string', 'max:70'],
            'payment_reference_prefix' => ['string', 'max:100'],
            // No dedicated URL rule exists (mirrors MailConfig's website_url /
            // logo_url, Pattern 001) — this is a link the admin controls, not
            // a value the system parses.
            'mandate_template_url' => ['nullable', 'string', 'max:255'],
        ];
        if (!$keepsStoredIban) {
            $rules['creditor_iban'] = ['required', 'string', 'iban'];
        }
        if ($request->getMethod() === 'POST') {
            $rules['creditor_id'] = ['required', 'string'];
        }

        if (!$this->validator->validate($body, $rules)) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $config = $this->sepaConfigService->updateConfig($body, $adminId);

        if (!$config) {
            return $this->json($response, ['error' => 'Failed to update SEPA configuration'], 500);
        }

        return $this->json($response, $config->toArray());
    }
}
