<?php

namespace Tests\Feature;

use App\Jobs\ForwardErrorEvent;
use App\Livewire\Super\Errors\Index;
use App\Models\ErrorEvent;
use App\Models\Hospital;
use App\Models\User;
use App\Support\Errors\ErrorTracker;
use App\Support\Errors\Scrubber;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/** Errors are kept grouped and scrubbed, the reference on the page finds them, and the app's crashes join them. */
class ErrorTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        config(['app.debug' => false]); // the error page people see, not the developer's
        Route::get('/__boom/{n}', fn (string $n) => throw new \RuntimeException("Patient amina@clinic.test (+256 700 111 222) row {$n} broke: 'Amina Nakato'"));
    }

    public function test_the_scrubber_takes_out_what_could_name_somebody(): void
    {
        $out = Scrubber::scrub("amina@clinic.test called from +256 700 111 222 or 0772 123456, NIN 12345678901, token=abc123, SQL 'Amina Nakato', json: \"name\": \"Amina\"");

        foreach (['amina@clinic.test', '700 111 222', '0772 123456', '12345678901', 'abc123', 'Amina'] as $leak) {
            $this->assertStringNotContainsString($leak, $out);
        }
    }

    public function test_an_error_is_recorded_once_per_kind_with_its_count_and_the_reference_on_the_page(): void
    {
        $first = $this->get('/__boom/1')->assertStatus(500);
        $this->get('/__boom/2')->assertStatus(500);

        $this->assertSame(1, ErrorEvent::count(), 'the same error at the same place is one row');
        $event = ErrorEvent::first();
        $this->assertSame(2, $event->occurrences);
        $this->assertSame('web', $event->source);
        $this->assertSame('/__boom/2', $event->path);
        $this->assertStringNotContainsString('amina@clinic.test', $event->message);
        $this->assertStringNotContainsString('Nakato', $event->message);
        $this->assertCount(2, $event->refs);

        // The page showed the first reference, and it finds the row.
        $this->assertStringContainsString('Reference <b>'.$event->refs[1].'</b>', $first->getContent());
    }

    public function test_a_fixed_error_that_comes_back_is_open_again(): void
    {
        $this->get('/__boom/1');
        ErrorEvent::query()->update(['resolved_at' => now()]);

        $this->get('/__boom/1');

        $this->assertNull(ErrorEvent::first()->resolved_at);
    }

    public function test_with_a_dsn_the_error_is_forwarded_without_personal_data(): void
    {
        config(['services.error_tracking.dsn' => 'https://publickey@glitchtip.example.test/7']);
        Http::fake();

        $this->get('/__boom/1');

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($request->url(), 'glitchtip.example.test/api/7/store/')
                && str_contains($request->header('X-Sentry-Auth')[0] ?? '', 'sentry_key=publickey')
                && ! str_contains($body, 'amina@clinic.test')
                && ! str_contains($body, 'Nakato');
        });
    }

    public function test_without_a_dsn_nothing_leaves_the_server(): void
    {
        Bus::fake();

        $this->get('/__boom/1');

        Bus::assertNotDispatched(ForwardErrorEvent::class);
    }

    public function test_the_app_sends_its_crashes_and_they_are_grouped_with_the_rest(): void
    {
        $hospital = Hospital::factory()->create();
        $nurse = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        \Laravel\Sanctum\Sanctum::actingAs($nurse);

        $this->postJson('/api/v1/diagnostics/errors', ['errors' => [
            ['kind' => 'StateError', 'message' => 'Bad state for amina@clinic.test', 'where' => 'lib/features/visits/visit_detail.dart:120', 'app_version' => '1.2.0'],
            ['kind' => 'StateError', 'message' => 'Bad state for amina@clinic.test', 'where' => 'lib/features/visits/visit_detail.dart:120', 'app_version' => '1.2.0'],
        ]])->assertOk()->assertJsonCount(2, 'data.refs');

        $event = ErrorEvent::where('source', 'app')->sole();
        $this->assertSame(2, $event->occurrences);
        $this->assertSame('1.2.0', $event->app_version);
        $this->assertSame($hospital->id, $event->last_hospital_id);
        $this->assertStringNotContainsString('amina@clinic.test', $event->message);

        $this->postJson('/api/v1/diagnostics/errors', ['errors' => array_fill(0, 21, ['kind' => 'X'])])->assertStatus(422);
    }

    public function test_the_super_admin_finds_an_error_by_its_reference_and_marks_it_fixed(): void
    {
        $this->get('/__boom/1');
        $event = ErrorEvent::first();
        $super = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin']);
        $super->syncSpatieRole();

        Livewire::actingAs($super)->test(Index::class)
            ->set('search', strtolower($event->last_ref))
            ->assertSee($event->last_ref)
            ->call('resolve', $event->id)
            ->set('search', '')
            ->assertDontSee($event->last_ref);

        $this->assertNotNull($event->fresh()->resolved_at);
        $this->actingAs($super)->get(route('super.errors.index'))->assertOk();
    }

    public function test_recording_never_throws_even_when_the_table_is_missing(): void
    {
        \Illuminate\Support\Facades\Schema::drop('error_events');

        app(ErrorTracker::class)->capture(new \RuntimeException('x'));

        $this->assertTrue(true);
    }
}
