<?php

namespace Tests\Unit;

use App\Support\SupportContact;
use Tests\TestCase;

class SupportContactTest extends TestCase
{
    public function test_reads_the_config_fallbacks_in_every_format(): void
    {
        config(['support.whatsapp' => '256700111222', 'support.phone' => '+256 700 111 222', 'support.email' => 'help@example.test', 'support.hours' => 'Always']);
        $c = SupportContact::platform();

        $this->assertSame('256700111222', $c->whatsappDigits());
        $this->assertSame('+256 700 111 222', $c->whatsappDisplay());
        $this->assertSame('tel:+256700111222', $c->phoneLink());
        $this->assertSame('help@example.test', $c->email());
        $this->assertSame(SupportContact::WHO_PLATFORM, $c->who());
    }

    public function test_digits_are_extracted_from_a_typed_number(): void
    {
        config(['support.whatsapp' => '+256 (700) 111-222']);

        $this->assertSame('256700111222', SupportContact::platform()->whatsappDigits());
    }

    public function test_a_link_carries_the_filled_message_and_no_unfilled_placeholders(): void
    {
        config(['support.whatsapp' => '256700111222']);
        $c = SupportContact::platform();

        $link = $c->waLink('admin.error', ['hospital' => 'Kampala Dental', 'ref' => 'AB12']);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://wa.me/256700111222?text=', $link);
        $this->assertSame('Hello True-Doctor, I got an error at Kampala Dental. Reference AB12.', $q['text']);
        $this->assertStringNotContainsString(':', $c->message('admin.help', ['screen' => 'Billing']));
    }

    public function test_no_whatsapp_line_means_no_link(): void
    {
        config(['support.whatsapp' => '']);

        $this->assertSame('', SupportContact::platform()->waLink('site.general'));
        $this->assertFalse(SupportContact::platform()->hasWhatsapp());
    }

    public function test_an_unknown_context_falls_back_to_the_general_message(): void
    {
        $this->assertSame(SupportContact::platform()->message('site.general'), SupportContact::platform()->message('nope.nothing'));
    }

    public function test_no_template_has_room_for_record_data(): void
    {
        $allowed = ['page', 'practice', 'hospital', 'role', 'screen', 'ref', 'limit', 'version', 'report', 'licence'];
        foreach (SupportContact::platform()->toArray()['templates'] as $key => $text) {
            preg_match_all('/:([a-z_]+)/', $text, $m);
            foreach ($m[1] as $name) {
                $this->assertContains($name, $allowed, "template {$key} uses :{$name}");
            }
        }
    }

    public function test_the_api_block_has_the_contact_who_and_templates(): void
    {
        $a = SupportContact::platform()->toArray();

        $this->assertArrayHasKey('whatsapp', $a);
        $this->assertArrayHasKey('site.demo', $a['templates']);
        $this->assertSame('platform', $a['who']);
    }
}
