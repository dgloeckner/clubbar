<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Security;

use App\Modules\Security\Services\EncryptionKeyExpiredException;
use App\Modules\Security\Services\EncryptionNotConfiguredException;
use PHPUnit\Framework\TestCase;

class SecurityExceptionsTest extends TestCase
{
    public function test_missing_key_maps_to_an_actionable_409(): void
    {
        $e = new EncryptionNotConfiguredException('no key');

        $this->assertSame(409, $e->getHttpStatusCode());
        $this->assertSame('encryption_not_configured', $e->getErrorCode());
    }

    public function test_expired_key_maps_to_an_actionable_409(): void
    {
        $e = new EncryptionKeyExpiredException('expired');

        $this->assertSame(409, $e->getHttpStatusCode());
        $this->assertSame('encryption_key_expired', $e->getErrorCode());
    }
}
