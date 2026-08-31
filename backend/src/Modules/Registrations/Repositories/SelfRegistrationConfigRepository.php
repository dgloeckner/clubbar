<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Repositories;

use App\Modules\Registrations\DTOs\SelfRegistrationConfigDto;
use PDO;

/**
 * The single-row switch and poster secret (migration 059).
 *
 * Same shape as `SepaConfigRepository` and `CreditLimitConfigRepository`: one
 * row, id 1, read on every request that touches the public surface.
 */
class SelfRegistrationConfigRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    public function get(): SelfRegistrationConfigDto
    {
        $row = $this->db->query('SELECT * FROM self_registration_config WHERE id = 1')->fetch();

        return SelfRegistrationConfigDto::fromRow($row ?: null);
    }
}
