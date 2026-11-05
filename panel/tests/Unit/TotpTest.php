<?php

namespace Tests\Unit;

use App\Services\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    public function test_rfc6238_vector(): void
    {
        // RFC 6238 test secret "12345678901234567890" in base32, time 59 -> 94287082 (8 digits) -> 287082
        $this->assertSame('287082', (new Totp)->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59));
    }

    public function test_verify_accepts_current_and_rejects_garbage(): void
    {
        $totp = new Totp;
        $secret = $totp->generateSecret();
        $this->assertTrue($totp->verify($secret, $totp->code($secret)));
        $this->assertFalse($totp->verify($secret, 'abcdef'));
        $this->assertStringStartsWith('otpauth://totp/', $totp->uri($secret, 'a@b.c', 'RHIS'));
    }
}
