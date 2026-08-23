<?php

declare(strict_types=1);

namespace App\Modules\CreditLimits\Controllers;

use App\Modules\CreditLimits\Domain\CreditLimitPolicy;
use App\Modules\CreditLimits\Services\CreditLimitConfigService;
use App\Shared\Http\JsonResponder;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The club's ceiling, read and written by the treasury (ADR-0046 decision 5).
 *
 * Both verbs are TREASURY — `admin` and `kassenwart` — deliberately unlike
 * `sepa-config`, whose read is TREASURY and whose writes are `admin`-only.
 * That asymmetry is about blast radius: a wrong Gläubiger-ID means a rejected
 * collection run and a Vorabankündigung that no longer matches what happened,
 * while a wrong ceiling mints no credential, exports no data, and is undone by
 * typing the right number.
 */
class CreditLimitConfigController
{
    use JsonResponder;

    public function __construct(
        private CreditLimitConfigService $creditLimitConfigService,
        private Validator $validator,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->creditLimitConfigService->getConfig()->toArray());
    }

    public function update(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        // `gte:0` rather than `gt:0`: zero is the club saying "cap nobody",
        // which is a setting and not an absence. A *negative* ceiling is
        // refused rather than reinterpreted — it would pass the `<= 0` test as
        // "unlimited" and grant every member infinite credit by accident
        // (ADR-0046 rule 3).
        $rules = [
            'default_limit_cents' => ['required', 'integer', 'gte:0', 'lte:' . CreditLimitPolicy::MAX_LIMIT_CENTS],
            'warn_threshold_percent' => ['required', 'integer', 'gte:1', 'lte:100'],
        ];

        if (!$this->validator->validate($body, $rules)) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $config = $this->creditLimitConfigService->updateConfig($body, $adminId);

        if (!$config) {
            return $this->json($response, ['error' => 'Failed to update credit limit configuration'], 500);
        }

        return $this->json($response, $config->toArray());
    }
}
