<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Controllers;

use App\Modules\Instance\Repositories\InstanceConfigRepository;
use App\Modules\Registrations\Documents\QrPosterService;
use App\Modules\Registrations\Domain\PosterSecret;
use App\Modules\Registrations\Services\SelfRegistrationAdminService;
use App\Shared\Config\AppConfig;
use App\Shared\Http\JsonResponder;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The club's own controls (#783, UC-A69).
 *
 * `admin`-only, and it sits with the club's other credentials for the reason
 * ADR-0036 put them together: the poster secret is a credential, and rotating
 * it takes every poster in the building out of service.
 *
 * The secret is never in a GET. Reading it back is `POST …/poster`, which
 * *produces* something — a printable sheet — rather than answering a question,
 * and is audited. A settings payload carrying a live credential would put it in
 * every page load, every browser cache and every screen share of this screen.
 */
class SelfRegistrationConfigController
{
    use JsonResponder;

    public function __construct(
        private SelfRegistrationAdminService $registrations,
        private QrPosterService $poster,
        private InstanceConfigRepository $instanceConfig,
        private AppConfig $appConfig,
        private Validator $validator,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->registrations->settings()->toArray());
    }

    /**
     * PATCH — the switch and the document URL.
     *
     * Both in one endpoint because they are one screen and one decision: a club
     * clearing its document URL is a club that can no longer be switched on, and
     * doing that in two requests makes the inconsistent state reachable between
     * them.
     */
    public function update(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        $rules = [];
        if (array_key_exists('enabled', $body)) {
            $rules['enabled'] = ['required', 'boolean'];
        }
        if (array_key_exists('disabled_reason', $body)) {
            $rules['disabled_reason'] = ['nullable', 'string', 'max:500'];
        }
        if (array_key_exists('document_url', $body)) {
            $rules['document_url'] = ['nullable', 'string', 'max:500'];
        }

        if (!$this->validator->validate($body, $rules)) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        // The URL first, deliberately. Enabling requires a document, so a
        // request that sets both in one go — the ordinary "configure and switch
        // on" — has to see the new URL by the time the switch is checked.
        if (array_key_exists('document_url', $body)) {
            $this->registrations->setDocumentUrl((string) ($body['document_url'] ?? ''), $adminId);
        }

        if (array_key_exists('enabled', $body)) {
            $this->registrations->setAvailability(
                filter_var($body['enabled'], FILTER_VALIDATE_BOOLEAN),
                $body['disabled_reason'] ?? null,
                $adminId,
            );
        }

        return $this->json($response, $this->registrations->settings()->toArray());
    }

    /**
     * POST …/secret — mint a new one, and kill every poster in the building.
     *
     * Answers with the settings rather than the secret. The new poster is
     * fetched by the download below, so the credential travels in a PDF an
     * admin prints, not in a JSON body a browser keeps.
     */
    public function rotateSecret(Request $request, Response $response): Response
    {
        $this->registrations->rotateSecret($request->getAttribute('admin_user_id'));

        return $this->json($response, $this->registrations->settings()->toArray());
    }

    /**
     * POST …/poster — the printable sheet.
     *
     * A POST because it reads a credential back, which is not a thing a GET
     * should do: a URL that returns the club's gate would be replayed by every
     * prefetcher, history entry and shared link that ever touched it.
     *
     * It deliberately does **not** rotate. A club that had to rotate in order to
     * reprint would invalidate every poster in the building every time somebody
     * spilled a drink on one.
     */
    public function poster(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $language = ($body['language'] ?? 'de') === 'en' ? 'en' : 'de';

        $secret = $this->registrations->currentSecret();
        $url = PosterSecret::url($this->appConfig->appUrl, $secret);
        $clubName = (string) ($this->instanceConfig->getConfig()['instance_name'] ?? '');

        $pdf = $this->poster->render($url, $clubName, $language);
        $response->getBody()->write($pdf);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="anmeldung-poster.pdf"')
            // The body is the club's gate rendered as a picture. Nothing between
            // here and the printer is entitled to keep a copy.
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
