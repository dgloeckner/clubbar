<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use App\Shared\Http\ClientIp;
use PHPUnit\Framework\TestCase;

/**
 * REMOTE_ADDR is trusted at face value unless TRUSTED_PROXIES says otherwise
 * (#886) — and even then, only the rightmost untrusted hop in X-Forwarded-For
 * is believed, never a client-supplied prefix.
 */
class ClientIpTest extends TestCase
{
    public function test_empty_trusted_proxies_returns_remote_addr_unchanged(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'];

        $this->assertSame('203.0.113.7', ClientIp::resolve($server, ''));
    }

    public function test_remote_addr_not_in_trusted_set_is_returned_unchanged(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'];

        $this->assertSame('203.0.113.7', ClientIp::resolve($server, '10.0.0.0/8'));
    }

    public function test_trusted_proxy_with_no_forwarded_for_header_falls_back_to_remote_addr(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5'];

        $this->assertSame('10.0.0.5', ClientIp::resolve($server, '10.0.0.0/8'));
    }

    public function test_trusted_proxy_single_hop_forwarded_for_is_used(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'];

        $this->assertSame('198.51.100.1', ClientIp::resolve($server, '10.0.0.5'));
    }

    /**
     * Rightmost-untrusted: a client can prepend anything to the header, but
     * the trusted proxy's own hop is what is believed.
     */
    public function test_rightmost_untrusted_hop_is_used_when_multiple_proxies_are_trusted(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.5',
            // Client-forged prefix, then two trusted proxies in the chain.
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.1, 10.0.0.2, 10.0.0.5',
        ];

        $this->assertSame('198.51.100.1', ClientIp::resolve($server, '10.0.0.0/8'));
    }

    public function test_every_hop_trusted_falls_back_to_remote_addr(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 10.0.0.2'];

        $this->assertSame('10.0.0.5', ClientIp::resolve($server, '10.0.0.0/8'));
    }

    public function test_a_malformed_hop_stops_the_walk_and_falls_back_to_remote_addr(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1, not-an-ip'];

        $this->assertSame('10.0.0.5', ClientIp::resolve($server, '10.0.0.0/8'));
    }

    public function test_a_bare_trusted_ip_is_treated_as_a_single_address(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.6', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'];

        // 10.0.0.5 (a /32) is trusted; 10.0.0.6 is not.
        $this->assertSame('10.0.0.6', ClientIp::resolve($server, '10.0.0.5'));
    }

    public function test_ipv6_cidrs_are_matched(): void
    {
        $server = ['REMOTE_ADDR' => 'fc00::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::1'];

        $this->assertSame('2001:db8::1', ClientIp::resolve($server, 'fc00::/7'));
    }

    public function test_multiple_trusted_entries_are_comma_separated(): void
    {
        $server = ['REMOTE_ADDR' => '172.16.5.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'];

        $this->assertSame('198.51.100.1', ClientIp::resolve($server, '10.0.0.0/8,172.16.0.0/12'));
    }

    public function test_a_malformed_trusted_proxies_entry_is_ignored_rather_than_throwing(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'];

        $this->assertSame('198.51.100.1', ClientIp::resolve($server, 'not-a-cidr, 10.0.0.0/8'));
    }

    public function test_missing_remote_addr_resolves_to_empty_string(): void
    {
        $this->assertSame('', ClientIp::resolve([], '10.0.0.0/8'));
    }

    public function test_whitespace_around_forwarded_for_hops_is_trimmed(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '  198.51.100.1  ,  10.0.0.5  '];

        $this->assertSame('198.51.100.1', ClientIp::resolve($server, '10.0.0.0/8'));
    }
}
