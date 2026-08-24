<?php

declare(strict_types=1);

namespace App\Modules\BankCodes\Controllers;

use App\Modules\BankCodes\Services\BankCodeService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Http\JsonResponder;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    public function __construct(
        private BankCodeService $bankCodeService,
        private AuditService $audit,
        private Logger $logger,
    ) {}

    /**
     * GET /api/admin/bank-lookup?blz=37040044
     * Returns bank name and BIC for a given BLZ (Bankleitzahl).
     *
     * Takes only the 8-digit BLZ instead of the full IBAN so that member
     * account numbers never end up in access logs via the query string.
     */
    public function lookup(Request $request, Response $response): Response
    {
        $blz = $request->getQueryParams()['blz'] ?? null;

        if (!is_string($blz) || $blz === '') {
            return $this->json($response, ['error' => 'Missing blz parameter'], 400);
        }

        $bank = $this->bankCodeService->lookupByBlz($blz);

        if ($bank === null) {
            return $this->json($response, [
                'bank_name' => null,
                'bic' => null,
                'message' => 'blz must be an 8-digit German Bankleitzahl',
            ]);
        }

        return $this->json($response, $bank);
    }

    /**
     * POST /api/admin/bank-codes/reimport
     *
     * Refill the Bundesbank BLZ table from the panel — the one post-restore
     * action a shared host cannot otherwise perform.
     *
     * `bank_codes` is `SCHEMA_ONLY` in a backup (ADR-0049 decision 1): ~20k
     * rows identical in every installation would dominate the size of every
     * nightly archive for no recovery value, so the structure travels and the
     * rows do not. Which means a restored installation comes back with the
     * table **empty**, and until now with no way to fill it: `install.php` 403s
     * once `storage/.installed` exists, and `bin/import-bank-codes.php` needs a
     * shell the reference host does not have.
     *
     * What breaks without it is narrower than it sounds, and worth stating so
     * nobody treats this as a data-loss bug: existing mandates are unaffected,
     * because `bank_name` is denormalised onto the row (migration `018`). What
     * stops working is bank-name resolution for *new* mandates. Keeping the
     * class and adding one post-restore click beats growing every archive.
     *
     * Admin-only, and reaching out to a third party to rewrite a whole table
     * earns an audit row (pattern-016). Synchronous: the download is a few
     * megabytes and the import a few seconds, and a job queue for something a
     * club does once after a restore would be machinery nobody maintains.
     */
    public function reimport(Request $request, Response $response): Response
    {
        try {
            $result = $this->bankCodeService->downloadAndImport();
        } catch (\Throwable $e) {
            // The Bundesbank being unreachable is not a bug in this
            // installation, and the admin can act on it: retry, or use the CLI
            // importer if they have a shell. Say which it was.
            $this->logger->error('Bank code re-import failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return $this->json($response, [
                'error' => 'Could not import the Bundesbank BLZ file: ' . $e->getMessage(),
            ], 502);
        }

        $this->audit->log(
            AuditAction::BANK_CODES_IMPORTED,
            EntityType::BANK_CODES,
            // The table is the entity — a re-import replaces all of it, and
            // naming one of ~20k codes as the subject would be arbitrary.
            'bank_codes',
            null,
            [
                'imported' => $result['imported'],
                'removed' => $result['removed'],
                'total' => $result['total'],
                'source' => $result['source'] ?? null,
            ],
            (string) $request->getAttribute('admin_user_id'),
        );

        return $this->json($response, $result);
    }
}
