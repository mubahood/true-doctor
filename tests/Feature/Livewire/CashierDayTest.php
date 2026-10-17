<?php

namespace Tests\Feature\Livewire;

use App\Enums\PaymentMethod;
use App\Livewire\Billing\CashierDay;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** The cashier's day on the web: the same figures as the app's day summary. */
class CashierDayTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Livewire::withoutLazyLoading();
        $this->h = Hospital::factory()->create(['currency' => 'UGX']);
        app(CurrentHospital::class)->set($this->h->id);
    }

    private function staff(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role, 'name' => ucfirst($role).' One']);
        $u->syncSpatieRole();

        return $u;
    }

    private function invoice(string $price, ?Hospital $h = null): Invoice
    {
        $h ??= $this->h;
        app(CurrentHospital::class)->set($h->id);
        $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $h->id])->id, 'status' => 'ongoing']);
        app(BillingService::class)->orderService($visit->fresh(), Service::factory()->create(['hospital_id' => $h->id, 'price' => $price])->id, 1);

        return app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', null);
    }

    public function test_the_day_adds_up_by_method_and_person_and_moves_between_days(): void
    {
        $cashier = $this->staff('accountant');
        $billing = app(BillingService::class);
        $invoice = $this->invoice('50000.00');
        $billing->recordPayment($invoice, PaymentMethod::Cash, '20000.00', [], $cashier->id);
        $billing->recordPayment($invoice->fresh(), PaymentMethod::MobileMoney, '15000.00', ['reference' => 'MM1'], $cashier->id);
        $yesterday = $billing->recordPayment($invoice->fresh(), PaymentMethod::Cash, '5000.00', [], $cashier->id);
        DB::table('payments')->where('id', $yesterday->id)->update(['created_at' => now()->subDay()]);

        // A payment in another hospital never reaches this till.
        $other = Hospital::factory()->create(['currency' => 'UGX']);
        $billing->recordPayment($this->invoice('99000.00', $other), PaymentMethod::Cash, '99000.00', [], null);
        app(CurrentHospital::class)->set($this->h->id);

        Livewire::actingAs($cashier)->test(CashierDay::class)
            ->assertSee('35,000')->assertSee('Mobile money')->assertSee('Accountant One')->assertDontSee('99,000')
            ->call('shift', -1)
            ->assertSee('5,000')->assertDontSee('35,000')
            ->call('shift', 5)
            ->assertSet('date', now()->toDateString());
    }

    public function test_a_future_or_broken_date_falls_back_to_today(): void
    {
        Livewire::actingAs($this->staff('accountant'))->test(CashierDay::class)
            ->set('date', now()->addDays(3)->toDateString())->assertSet('date', now()->toDateString())
            ->set('date', 'yesterday')->assertSet('date', now()->toDateString());
    }

    public function test_only_billing_staff_open_it_and_the_printout_matches(): void
    {
        $this->actingAs($this->staff('nurse'))->get(route('admin.cashier.day'))->assertForbidden();

        $this->actingAs($this->staff('accountant'))->get(route('admin.cashier.day'))->assertOk()->assertSee("Cashier's day", false);
        $this->get(route('admin.cashier.day.pdf', ['date' => now()->toDateString()]))->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
