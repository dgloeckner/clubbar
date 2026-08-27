<?php

declare(strict_types=1);

namespace App\Modules\Backups\Controllers;

use App\Modules\Backups\Services\ArchiveDirectory;
use App\Modules\Backups\Services\BackupsInventory;
use App\Modules\Backups\Services\RemoteLookup;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * What this installation holds, which keys open it, and a way to fetch one
 * (#693, ADR-0049).
 *
 * ## Three routes because they have three costs
 *
 * | Route | Cost | Fails how |
 * |---|---|---|
 * | `GET /api/admin/backups` | a directory scan and a header read per archive | not at all, in practice |
 * | `GET /api/admin/backups/remote` | one bounded call to the storage provider | degrades to last night's snapshot |
 * | `GET /api/admin/backups/{name}` | streams a file | 404 |
 *
 * Splitting them is the design rather than REST tidiness. The page renders the
 * first immediately and asks for the second afterwards, so a throttled tenant
 * costs one column instead of the table — see {@see BackupsInventory} and
 * {@see RemoteLookup} for the whole argument.
 *
 * ## `admin` only, and read-only
 *
 * `RouteRoleMap` puts all three behind `admin`, matching every other backup
 * surface: an archive carries the audit log, every admin's TOTP ciphertext and
 * the database password, and ADR-0049 draws that office boundary for custody of
 * the key. A Kassenwart holds the IBAN key because SEPA collection needs it;
 * that is a different key and a different remit.
 *
 * Nothing here writes. There is no delete, no retry-upload and no key
 * lifecycle: #703 removed the application's key register deliberately, because
 * custody belongs in the club's own register on paper, where a restore cannot
 * rewrite it. This page is the **checklist to walk that register against**, and
 * a button that retired a key here would be the application quietly becoming
 * the register again.
 */
class BackupsController
{
    use JsonResponder;

    public function __construct(
        private BackupsInventory $inventory,
        private RemoteLookup $remoteLookup,
        private string $backupDirectory,
    ) {}

    /** The local view: everything the filesystem knows, and nothing else. */
    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'archives' => $this->inventory->archives(),
            'keys' => $this->inventory->keys(),
        ]);
    }

    /**
     * The enrichment: which archives the store holds.
     *
     * Its own route so the page can render without it, and so a store that has
     * gone slow cannot delay the list of what a club has locally.
     */
    public function remote(Request $request, Response $response): Response
    {
        return $this->json($response, $this->remoteLookup->look());
    }

    /**
     * One archive, streamed.
     *
     * The bytes are sealed to keys this server does not hold the other half of,
     * so this hands over something the server itself cannot read — which is
     * what makes an ordinary authenticated download the right shape rather than
     * a re-authentication step.
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        $name = (string) ($args['name'] ?? '');

        // `basename()` before anything touches the filesystem: the name arrives
        // from the URL, and a caller sending `../../config.php` must not be
        // able to reach outside the backup directory. The prefix and extension
        // check below is the second gate — this route serves archives, not
        // whatever else the directory happens to contain.
        $name = basename($name);

        $isArchive = str_starts_with($name, ArchiveDirectory::FILENAME_PREFIX)
            && str_ends_with($name, ArchiveDirectory::EXTENSION);

        $path = rtrim($this->backupDirectory, '/') . '/' . $name;

        if (!$isArchive || !is_file($path)) {
            return $this->json($response, [
                'error' => 'not_found',
                'message' => 'No such archive.',
            ], 404);
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return $this->json($response, [
                'error' => 'not_readable',
                'message' => 'The archive exists but could not be opened.',
            ], 500);
        }

        return $response
            ->withBody(new \Slim\Psr7\Stream($stream))
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('Content-Length', (string) filesize($path))
            // Sealed, but still the club's whole database: no proxy or shared
            // browser cache should keep a copy after the download (ADR-0031).
            ->withHeader('Cache-Control', 'no-store');
    }
}
