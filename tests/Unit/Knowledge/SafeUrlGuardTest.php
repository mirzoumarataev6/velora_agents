<?php

namespace Tests\Unit\Knowledge;

use App\Services\Knowledge\SafeUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SafeUrlGuardTest extends TestCase
{
    public static function unsafeUrls(): array
    {
        return [
            'loopback IPv4' => ['http://127.0.0.1/admin'],
            'loopback IPv6' => ['http://[::1]/admin'],
            'private class A' => ['http://10.1.2.3/'],
            'private class B' => ['http://172.16.5.5/'],
            'private class C' => ['http://192.168.1.1/'],
            'link-local' => ['http://169.254.169.254/latest/meta-data'],
            'non-http scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://example.com/file'],
            'missing host' => ['http:///path'],
            'not a url' => ['not a url at all'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_it_rejects_unsafe_urls(string $url): void
    {
        $this->expectException(RuntimeException::class);

        SafeUrlGuard::assertSafe($url);
    }

    public function test_it_accepts_a_public_ip_literal(): void
    {
        [$ip, $port] = SafeUrlGuard::assertSafe('http://8.8.8.8/robots.txt');

        $this->assertSame('8.8.8.8', $ip);
        $this->assertSame(80, $port);
    }

    public function test_it_uses_explicit_port_when_given(): void
    {
        [, $port] = SafeUrlGuard::assertSafe('https://8.8.8.8:8443/');

        $this->assertSame(8443, $port);
    }
}
