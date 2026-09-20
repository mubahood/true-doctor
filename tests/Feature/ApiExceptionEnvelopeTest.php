<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * HMS_PLAN.md §3.D — one consistent JSON envelope for every API error,
 * wired in bootstrap/app.php's withExceptions().
 */
class ApiExceptionEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/__test')->group(function () {
            Route::get('validation', function () {
                throw ValidationException::withMessages(['email' => 'The email field is required.']);
            });
            Route::get('not-found', fn () => abort(404));
            Route::get('forbidden', fn () => abort(403, 'Nope.'));
            Route::get('boom', function () {
                throw new \RuntimeException('Something broke internally.');
            });
        });

        // Outside api/* — only gets the envelope when the request itself
        // asks for JSON, never by URL alone.
        Route::get('/__test/web-not-found', fn () => abort(404));
    }

    public function test_validation_exception_uses_the_envelope(): void
    {
        $this->getJson('/api/__test/validation')
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 'validation_failed',
            ])
            ->assertJsonPath('errors.email.0', 'The email field is required.');
    }

    public function test_404_uses_the_envelope(): void
    {
        $this->getJson('/api/__test/not-found')
            ->assertStatus(404)
            ->assertJson(['success' => false, 'code' => 'not_found']);
    }

    public function test_abort_403_uses_the_envelope_and_keeps_the_message(): void
    {
        $this->getJson('/api/__test/forbidden')
            ->assertStatus(403)
            ->assertJson(['success' => false, 'code' => 'forbidden', 'message' => 'Nope.']);
    }

    public function test_unhandled_exception_hides_the_message_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/api/__test/boom')
            ->assertStatus(500)
            ->assertJson(['success' => false, 'code' => 'server_error', 'message' => 'Something went wrong.']);
    }

    public function test_a_plain_web_request_still_gets_the_default_html_error_page(): void
    {
        $response = $this->get('/__test/web-not-found');

        $response->assertStatus(404);
        $this->assertStringNotContainsString('"success"', $response->getContent());
    }

    public function test_a_web_route_returns_the_envelope_when_json_is_explicitly_requested(): void
    {
        $this->getJson('/__test/web-not-found')
            ->assertStatus(404)
            ->assertJson(['success' => false, 'code' => 'not_found']);
    }
}
