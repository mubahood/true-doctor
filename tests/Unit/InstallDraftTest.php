<?php

namespace Tests\Unit;

use App\Services\Install\InstallDraft;
use Tests\TestCase;

class InstallDraftTest extends TestCase
{
    public function test_answers_are_encrypted_and_bound_to_the_installing_browser(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'td-draft-');
        $key = 'base64:'.base64_encode(random_bytes(32));
        try {
            $draft = new InstallDraft;
            $token = $draft->save($file, $key, ['owner_password' => 'unusual$\"password']);
            $this->assertStringNotContainsString('unusual', (string) file_get_contents($file));
            $this->assertSame('unusual$\"password', $draft->load($file, $key, $token)['owner_password']);
            $this->expectException(\DomainException::class);
            $draft->load($file, $key, 'another browser');
        } finally {
            unlink($file);
        }
    }
}
