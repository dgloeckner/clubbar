<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Logging\Logger;
use App\Shared\Sepa\MandateReferenceCounterRepository;
use App\Shared\Sepa\MandateReferenceMinter;
use PDO;

/**
 * The mandate reference minter, for tests that need one wired.
 *
 * `real()` draws from the install's counter row exactly as production does, so
 * a Feature test gets the references the API would hand out. `counting()` is
 * for SQLite-backed unit tests: `LAST_INSERT_ID()` does not exist there, and a
 * test that wants to assert *whether* a number was drawn needs to see the
 * draws rather than the SQL anyway.
 */
final class MandateReferences
{
    public static function real(PDO $db, Logger $logger): MandateReferenceMinter
    {
        return new MandateReferenceMinter(
            new MandateReferenceCounterRepository($db),
            new SepaConfigRepository($db, $logger),
        );
    }

    /** A minter over a counter that just counts, and says how often it was asked. */
    public static function counting(PDO $db, Logger $logger): MandateReferenceMinter
    {
        return new MandateReferenceMinter(
            new CountingMandateReferenceCounter(),
            new SepaConfigRepository($db, $logger),
        );
    }
}
