<?php

namespace Tests\Unit;

use App\Services\PatientService;
use App\Support\CurrentHospital;
use App\Support\VerificationCode;
use PHPUnit\Framework\TestCase;

class PatientNumberTest extends TestCase
{
    private function service(): PatientService
    {
        return new PatientService(new CurrentHospital);
    }

    public function test_number_has_expected_format(): void
    {
        $no = $this->service()->makeNumber(123, 2026);
        $this->assertSame('PT-2026-000123', substr($no, 0, 14));
        $this->assertSame(15, strlen($no)); // core (14) + 1 check char
    }

    public function test_number_carries_a_valid_checksum(): void
    {
        $no = $this->service()->makeNumber(482, 2026);
        $this->assertTrue(VerificationCode::isValid($no));
        // A tampered last char fails the check.
        $this->assertFalse(VerificationCode::isValid(substr($no, 0, -1).'Z'));
    }

    public function test_sequence_is_zero_padded_to_six(): void
    {
        $this->assertStringStartsWith('PT-2026-000007', $this->service()->makeNumber(7, 2026));
        $this->assertStringStartsWith('PT-2026-123456', $this->service()->makeNumber(123456, 2026));
    }
}
