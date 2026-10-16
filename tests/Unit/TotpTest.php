<?php

namespace Tests\Unit;

use App\Support\TwoFactor\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** RFC 6238 appendix B, SHA1, truncated to the 6 digits authenticator apps show. */
    public function test_the_rfc_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->assertSame($code, Totp::code($secret, Totp::step($time)), "at {$time}");
        }
    }

    public function test_base32_round_trips(): void
    {
        $bytes = random_bytes(20);

        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        $this->assertSame(32, strlen(Totp::newSecret()));
    }

    public function test_one_step_of_drift_is_accepted_and_two_are_not(): void
    {
        $secret = Totp::newSecret();
        $now = 1_800_000_000;

        $this->assertSame(Totp::step($now), Totp::matchingStep($secret, Totp::code($secret, Totp::step($now)), $now));
        $this->assertNotNull(Totp::matchingStep($secret, Totp::code($secret, Totp::step($now) - 1), $now));
        $this->assertNull(Totp::matchingStep($secret, Totp::code($secret, Totp::step($now) - 2), $now));
        $this->assertNull(Totp::matchingStep($secret, '12345', $now));
    }

    public function test_spaces_in_a_typed_code_are_ignored(): void
    {
        $secret = Totp::newSecret();
        $code = Totp::code($secret, Totp::step(1_800_000_000));

        $this->assertNotNull(Totp::matchingStep($secret, substr($code, 0, 3).' '.substr($code, 3), 1_800_000_000));
    }

    public function test_the_uri_an_app_scans(): void
    {
        $uri = Totp::uri('ABC', 'amina@clinic.test');

        $this->assertStringStartsWith('otpauth://totp/True-Doctor%3Aamina%40clinic.test?secret=ABC&issuer=True-Doctor', $uri);
    }
}
