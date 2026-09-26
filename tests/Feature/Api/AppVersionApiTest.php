<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppVersionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_app_older_than_the_minimum_is_told_to_update_and_others_pass(): void
    {
        config(['services.mobile.min_version' => '1.2.0', 'services.mobile.download_url' => 'https://example.test/app']);

        $this->withHeader('X-App-Version', '1.1.9')->postJson('/api/v1/auth/login', ['email' => 'a@b.c', 'password' => 'x'])
            ->assertStatus(426)
            ->assertJsonPath('code', 'upgrade_required')
            ->assertJsonPath('data.minimum', '1.2.0')
            ->assertJsonPath('data.download_url', 'https://example.test/app');

        // Current, or not an app at all (the web, Field Mode): through to the real answer.
        $this->withHeader('X-App-Version', '1.2.0')->postJson('/api/v1/auth/login', ['email' => 'a@b.c', 'password' => 'x'])->assertStatus(401);
        $this->flushHeaders();
        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.c', 'password' => 'x'])->assertStatus(401);
    }
}
