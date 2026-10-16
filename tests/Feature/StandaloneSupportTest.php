<?php

namespace Tests\Feature;

use App\Services\Releases\StageStandaloneRelease;
use App\Support\SupportContact;
use Tests\TestCase;

/** A standalone copy says who to ask before it is even installed: stamped in at build time. */
class StandaloneSupportTest extends TestCase
{
    public function test_the_guide_line_names_whatsapp_phone_and_email(): void
    {
        config(['support.whatsapp' => '256700111222', 'support.phone' => '+256 700 111 222', 'support.email' => 'help@example.test', 'support.hours' => 'Always']);

        $line = StageStandaloneRelease::supportLine(SupportContact::platform());

        $this->assertStringContainsString('WhatsApp **+256 700 111 222**', $line);
        $this->assertStringContainsString('https://wa.me/256700111222?text=', $line);
        $this->assertStringContainsString('help@example.test', $line);
    }

    public function test_the_guide_has_the_placeholder_and_never_the_number(): void
    {
        $guide = (string) file_get_contents(resource_path('standalone/INSTALL.md'));

        $this->assertStringContainsString('{{support}}', $guide);
        $this->assertDoesNotMatchRegularExpression('/783[ -]?204[ -]?665/', $guide);
    }

    public function test_the_installer_reads_the_stamped_contact(): void
    {
        $installer = (string) file_get_contents(public_path('install.php'));

        $this->assertStringContainsString("\$root.'/SUPPORT.json'", $installer);
        $this->assertStringContainsString('https://wa.me/', $installer);
    }
}
