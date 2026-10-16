<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private User $clerk;

    private array $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        [$this->h, $this->clerk, $this->invoice] = $this->hospitalWithInvoice();
        Sanctum::actingAs($this->clerk);
    }

    private function hospitalWithInvoice(): array
    {
        $h = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($h->id);
        $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $h->id])->id, 'status' => 'ongoing']);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'name' => 'Consultation', 'price' => '30000.00']);
        app(BillingService::class)->orderService($visit->fresh(), $svc->id, 1);
        $clerk = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $clerk->syncSpatieRole();
        Sanctum::actingAs($clerk);
        $invoice = $this->postJson("/api/v1/visits/{$visit->uuid}/invoice")->assertCreated()->json('data');

        return [$h, $clerk, $invoice];
    }

    private function pay(array $body, ?string $key, array $invoice = null)
    {
        return $this->postJson('/api/v1/invoices/'.($invoice ?? $this->invoice)['uuid'].'/payments', $body, $key === null ? [] : ['Idempotency-Key' => $key]);
    }

    private const PAY = ['method' => 'cash', 'amount' => 10000];

    public function test_a_replay_returns_the_same_answer_and_the_controller_runs_once(): void
    {
        $first = $this->pay(self::PAY, 'k-1')->assertCreated();
        $second = $this->pay(self::PAY, 'k-1')->assertCreated()->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertNull($first->headers->get('Idempotent-Replay'));
        $this->assertSame(1, Payment::withoutGlobalScopes()->count());
    }

    public function test_the_same_key_with_a_different_body_is_refused(): void
    {
        $this->pay(self::PAY, 'k-2')->assertCreated();

        $this->pay(['method' => 'cash', 'amount' => 20000], 'k-2')->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
        $this->assertSame(1, Payment::withoutGlobalScopes()->count());
    }

    public function test_the_body_is_compared_regardless_of_key_order(): void
    {
        $this->pay(['method' => 'cash', 'amount' => 10000], 'k-3')->assertCreated();
        $this->pay(['amount' => 10000, 'method' => 'cash'], 'k-3')->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(1, Payment::withoutGlobalScopes()->count());
    }

    public function test_a_request_still_in_flight_is_told_so(): void
    {
        $lock = Cache::lock('idempotency:'.$this->clerk->getKey().':busy', 30);
        $this->assertTrue($lock->get());

        $this->pay(self::PAY, 'busy')->assertStatus(409)->assertJsonPath('code', 'request_in_progress');
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());

        $lock->release();
        $this->pay(self::PAY, 'busy')->assertCreated();
    }

    public function test_another_user_with_the_same_key_is_not_replayed(): void
    {
        $this->pay(self::PAY, 'shared')->assertCreated();

        $other = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'receptionist']);
        $other->syncSpatieRole();
        Sanctum::actingAs($other);
        $this->pay(['method' => 'cash', 'amount' => 5000], 'shared')->assertCreated()->assertHeaderMissing('Idempotent-Replay');

        $this->assertSame(2, Payment::withoutGlobalScopes()->count());
    }

    public function test_a_hospital_never_sees_another_hospitals_stored_answer(): void
    {
        $this->pay(self::PAY, 'same-key')->assertCreated();

        [, $clerkB, $invoiceB] = $this->hospitalWithInvoice();
        Sanctum::actingAs($clerkB);
        $res = $this->pay(self::PAY, 'same-key', $invoiceB)->assertCreated();

        $this->assertNull($res->headers->get('Idempotent-Replay'));
        $this->assertNotSame($this->invoice['uuid'], $res->json('data.uuid'));
        $this->assertSame(2, Payment::withoutGlobalScopes()->count());
    }

    public function test_a_validation_refusal_is_not_stored_so_the_corrected_request_runs(): void
    {
        $this->pay(['method' => 'cash', 'amount' => 500000], 'fix')->assertStatus(422);
        $this->assertSame(0, DB::table('idempotency_keys')->where('key', 'fix')->count());

        $this->pay(self::PAY, 'fix')->assertCreated();
        $this->assertSame(1, Payment::withoutGlobalScopes()->count());
    }

    public function test_a_missing_record_answer_is_not_kept(): void
    {
        $this->postJson('/api/v1/invoices/00000000-0000-0000-0000-000000000000/payments', self::PAY, ['Idempotency-Key' => 'gone'])->assertStatus(404);
        $this->assertSame(0, DB::table('idempotency_keys')->where('key', 'gone')->count(), 'an exception-rendered 404 is not kept');
    }

    public function test_without_the_header_nothing_changes(): void
    {
        $this->pay(['method' => 'cash', 'amount' => 1000], null)->assertCreated();
        $this->pay(['method' => 'cash', 'amount' => 1000], null)->assertCreated();

        $this->assertSame(2, Payment::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_a_malformed_key_is_refused_and_an_old_row_is_ignored(): void
    {
        $this->pay(self::PAY, 'has space')->assertStatus(422);
        $this->pay(self::PAY, str_repeat('a', 101))->assertStatus(422);

        $this->pay(self::PAY, 'old')->assertCreated();
        DB::table('idempotency_keys')->where('key', 'old')->update(['created_at' => now()->subHours(25)]);
        $this->pay(self::PAY, 'old')->assertCreated();
        $this->assertSame(2, Payment::withoutGlobalScopes()->count());
    }

    public function test_it_works_on_a_standalone_installation(): void
    {
        $marker = storage_path('framework/testing/installed-idem-'.bin2hex(random_bytes(4)).'.json');
        file_put_contents($marker, json_encode(['edition' => 'standalone', 'version' => '1.0.0']));
        config()->set('edition.name', 'standalone');
        config()->set('edition.installed_path', $marker);

        try {
            $this->pay(self::PAY, 'sa-1')->assertCreated();
            $this->pay(self::PAY, 'sa-1')->assertCreated()->assertHeader('Idempotent-Replay', 'true');
            $this->assertSame(1, Payment::withoutGlobalScopes()->count());
        } finally {
            @unlink($marker);
        }
    }
}
