<?php

namespace Tests\Feature;

use App\Enums\DentalTreatmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Livewire\Ui\SelectSearch;
use App\Livewire\Visits\Panels\OrderDetail;
use App\Livewire\Visits\Panels\Orders;
use App\Models\AuditEntry;
use App\Models\Hospital;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Services\DentalService;
use App\Services\OrderDesk;
use App\Services\OrderService;
use App\Support\CurrentHospital;
use App\Support\Practice\Practice;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A dental clinic's round of order changes, applied through the practice:
 * the form's words, the kinds of work offered, who an order can be assigned
 * to, and changing an order after it was placed (docs/orders.md).
 */
class OrderEditingTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private User $dentist;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Livewire::withoutLazyLoading();

        $this->h = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        app(CurrentHospital::class)->set($this->h->id);
        $this->dentist = $this->staff('doctor');
        $this->visit = $this->visitFor($this->h, $this->dentist);
    }

    private function staff(string $role, array $extra = [], ?Hospital $in = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($in ?? $this->h)->id, 'role' => $role] + $extra);
        $u->syncSpatieRole();

        return $u;
    }

    private function visitFor(Hospital $h, User $doctor): Visit
    {
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        return Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id, 'status' => 'ongoing', 'doctor_user_id' => $doctor->id]);
    }

    private function service(string $name, string $price, ?string $max = null): Service
    {
        return Service::factory()->create(['hospital_id' => $this->h->id, 'name' => $name, 'price' => $price, 'max_price' => $max]);
    }

    /** A procedure order with one charge on it, the way a dental chair raises one. */
    private function charged(Service $service, string $qty = '1'): Order
    {
        $order = app(OrderService::class)->place($this->visit, OrderType::Procedure, $service->name, [], $this->dentist->id);
        app(OrderService::class)->addServiceItem($order, $service, $qty, $this->dentist->id);

        return $order->fresh();
    }

    // ── Words and kinds follow the practice ──────────────────────────────

    public function test_a_dental_clinic_is_offered_treatments_without_admission(): void
    {
        $kinds = OrderDesk::kinds();
        $this->assertNotContains(OrderType::Admission, $kinds);
        $this->assertContains(OrderType::Procedure, $kinds);
        $this->assertSame(['kind' => 'Treatment', 'title' => 'Service', 'assignee' => 'Dentist assigned'], OrderDesk::wording());

        $this->actingAs($this->dentist);
        Livewire::test(Orders::class, ['visitId' => $this->visit->id])
            ->call('openAdd')
            ->assertSee('Treatment')
            ->assertSee('Dentist assigned')
            ->assertSee('Service')
            ->assertDontSee('Admission')
            ->assertDontSee('What kind of work?')
            ->assertDontSee('What is it?');
    }

    public function test_a_general_hospital_keeps_admission_and_plain_words(): void
    {
        $general = Practice::of(Hospital::factory()->create());

        $this->assertContains(OrderType::Admission, OrderDesk::kinds($general));
        $this->assertSame(['kind' => 'Type', 'title' => 'Service', 'assignee' => 'Clinician assigned'], OrderDesk::wording($general));
    }

    public function test_an_admission_is_refused_by_the_service_the_panel_and_the_api(): void
    {
        try {
            app(OrderDesk::class)->place($this->visit, OrderType::Admission, ['title' => 'Admit', 'bed_id' => 1], $this->dentist);
            $this->fail('a clinic with no wards admitted a patient');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not admit patients', $e->getMessage());
        }

        $this->actingAs($this->dentist);
        Livewire::test(Orders::class, ['visitId' => $this->visit->id])
            ->call('openAdd')
            ->set('type', OrderType::Admission->value)
            ->set('title', 'Admit')
            ->call('add')
            ->assertHasErrors('type');

        Sanctum::actingAs($this->dentist);
        $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'admission', 'title' => 'Admit', 'bed_id' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['type'], 'errors');
        $this->assertSame(0, Order::count());
    }

    public function test_the_apps_meta_offers_the_same_kinds_and_words(): void
    {
        Sanctum::actingAs($this->dentist);
        $meta = $this->getJson('/api/v1/meta')->assertOk();

        $kinds = array_column($meta->json('data.orders.kinds'), 'value');
        $this->assertNotContains('admission', $kinds);
        $this->assertContains('procedure', $kinds);
        $meta->assertJsonPath('data.orders.labels.kind', 'Treatment')
            ->assertJsonPath('data.orders.labels.assignee', 'Dentist assigned');
    }

    // ── Who an order can be assigned to ──────────────────────────────────

    public function test_only_active_clinicians_of_this_hospital_can_be_assigned(): void
    {
        $nurse = $this->staff('nurse');
        $owner = $this->staff('hospital_admin', ['sees_patients' => true]);
        $retired = $this->staff('doctor', ['is_active' => false]);
        $elsewhere = $this->staff('doctor', [], Hospital::factory()->create());

        Sanctum::actingAs($this->dentist);
        foreach ([$nurse, $retired, $elsewhere] as $wrong) {
            $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'procedure', 'title' => 'Scaling', 'assigned_to' => $wrong->id])
                ->assertStatus(422)->assertJsonPath('errors.assigned_to.0', 'Choose someone who sees patients here.');
        }
        $this->postJson("/api/v1/visits/{$this->visit->uuid}/orders", ['type' => 'procedure', 'title' => 'Scaling', 'assigned_to' => $owner->id])
            ->assertCreated();

        $people = array_column($this->getJson('/api/v1/orders/assignees')->assertOk()->assertJsonPath('data.label', 'Dentist assigned')->json('data.people'), 'id');
        sort($people);
        $this->assertSame([$this->dentist->id, $owner->id], $people);

        $this->actingAs($this->dentist);
        $ids = array_column(Livewire::test(SelectSearch::class, ['resource' => 'clinicians', 'name' => 'assigned_to'])->set('query', '')->call('openList')->get('options'), 'id');
        sort($ids);
        $this->assertSame([$this->dentist->id, $owner->id], $ids);
    }

    public function test_a_new_order_starts_with_the_visits_dentist(): void
    {
        $this->actingAs($this->staff('receptionist'));

        Livewire::test(Orders::class, ['visitId' => $this->visit->id])
            ->call('openAdd')
            ->assertSet('assigned_to', $this->dentist->id);
    }

    // ── Changing an order ────────────────────────────────────────────────

    public function test_the_service_notes_and_dentist_change_and_the_trail_says_who_and_what(): void
    {
        $other = $this->staff('doctor');
        $order = app(OrderService::class)->place($this->visit, OrderType::Procedure, 'Scaling', ['notes' => 'Upper'], $this->dentist->id);
        $this->actingAs($this->dentist);

        app(OrderDesk::class)->edit($order, ['title' => 'Scaling and polishing', 'notes' => 'Upper and lower', 'assigned_to' => $other->id], $this->dentist);

        $order->refresh();
        $this->assertSame('Scaling and polishing', $order->title);
        $this->assertSame('Upper and lower', $order->notes);
        $this->assertSame($other->id, (int) $order->assigned_to);
        $this->assertSame($this->dentist->id, (int) $order->edited_by);
        $this->assertNotNull($order->edited_at);

        $entry = AuditEntry::query()->where('log_name', 'order')->where('event', 'updated')->where('subject_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame($this->dentist->id, (int) $entry->causer_id);
        $this->assertSame('Scaling', $entry->oldValues()['title']);
        $this->assertSame('Scaling and polishing', $entry->newValues()['title']);
    }

    public function test_choosing_another_service_moves_the_bill_line_with_it(): void
    {
        $filling = $this->service('Filling', '40000.00');
        $crown = $this->service('Crown', '300000.00');
        $order = $this->charged($filling);

        $this->actingAs($this->dentist);
        app(OrderDesk::class)->edit($order, ['service_id' => $crown->id, 'quantity' => '1'], $this->dentist);

        $order->refresh();
        $live = $order->items()->where('status', '!=', OrderItemStatus::Cancelled->value)->get();
        $this->assertCount(1, $live);
        $this->assertSame('Crown', $live->first()->name);
        $this->assertSame('300000.00', (string) $live->first()->line_total);
        $this->assertSame('Crown', $order->title, 'the name follows the charge');
        $this->assertSame(1, $order->items()->where('status', OrderItemStatus::Cancelled->value)->count(), 'the old charge is kept, cancelled');
        $this->assertSame('300000.00', app(BillingService::class)->totalsFor($this->visit->fresh())['subtotal']);
    }

    public function test_a_ranged_price_moves_inside_its_range_and_a_fixed_one_does_not(): void
    {
        $extraction = $this->service('Difficult extraction', '50000.00', '80000.00');
        $order = $this->charged($extraction, '2');
        $line = $order->items()->firstOrFail();
        $this->actingAs($this->dentist);

        app(OrderDesk::class)->edit($order, ['lines' => [['id' => $line->id, 'quantity' => '1', 'unit_price' => '65000']]], $this->dentist);
        $line->refresh();
        $this->assertSame('65000.00', (string) $line->unit_price);
        $this->assertSame('65000.00', (string) $line->line_total);

        $this->expectExceptionMessage('is charged between');
        app(OrderDesk::class)->edit($order, ['unit_price' => '90000'], $this->dentist);
    }

    public function test_a_fixed_price_cannot_be_changed(): void
    {
        $order = $this->charged($this->service('Filling', '40000.00'));
        $this->actingAs($this->dentist);

        $this->expectExceptionMessage('has a fixed price');
        app(OrderDesk::class)->edit($order, ['unit_price' => '10000'], $this->dentist);
    }

    public function test_after_invoicing_the_charge_is_refused_but_the_words_may_change(): void
    {
        $order = $this->charged($this->service('Filling', '40000.00'));
        app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->dentist->id);
        $this->actingAs($this->dentist);

        try {
            app(OrderDesk::class)->edit($order, ['quantity' => '2'], $this->dentist);
            $this->fail('an invoiced charge changed');
        } catch (RuntimeException $e) {
            $this->assertSame('This visit has been invoiced, so its charges can no longer be changed.', $e->getMessage());
        }
        $this->assertSame('1.00', (string) $order->items()->firstOrFail()->quantity);

        app(OrderDesk::class)->edit($order, ['notes' => 'Upper left'], $this->dentist);
        $this->assertSame('Upper left', $order->fresh()->notes);
    }

    public function test_a_completed_or_cancelled_order_cannot_be_changed(): void
    {
        $order = $this->charged($this->service('Filling', '40000.00'));
        app(OrderService::class)->transition($order, OrderStatus::Completed, $this->dentist->id);
        $this->actingAs($this->dentist);

        $this->assertFalse(OrderDesk::canEdit($order->fresh(), $this->dentist));
        $this->expectExceptionMessage('This order is completed');
        app(OrderDesk::class)->edit($order->fresh(), ['title' => 'Something else'], $this->dentist);
    }

    public function test_only_someone_who_could_place_the_kind_may_change_it(): void
    {
        $general = Hospital::factory()->create();
        app(CurrentHospital::class)->set($general->id);
        $doctor = $this->staff('doctor', [], $general);
        $visit = $this->visitFor($general, $doctor);
        $lab = app(OrderService::class)->place($visit, OrderType::Lab, 'Lab tests', [], $doctor->id);

        Sanctum::actingAs($this->staff('receptionist', [], $general));
        $this->patchJson("/api/v1/orders/{$lab->uuid}", ['notes' => 'Fasting'])->assertStatus(403);

        Sanctum::actingAs($this->staff('nurse', [], $general));
        $this->patchJson("/api/v1/orders/{$lab->uuid}", ['notes' => 'Fasting'])->assertStatus(403);

        Sanctum::actingAs($doctor);
        $this->patchJson("/api/v1/orders/{$lab->uuid}", ['notes' => 'Fasting'])->assertOk()->assertJsonPath('data.notes', 'Fasting');

        // A lab order already with the bench keeps its technician: sending the
        // same person back is no change, and only a NEW assignee must see patients.
        $tech = $this->staff('lab_technician', [], $general);
        $lab->forceFill(['assigned_to' => $tech->id])->save();
        $this->patchJson("/api/v1/orders/{$lab->uuid}", ['notes' => 'Fasting, 8 hours', 'assigned_to' => $tech->id])
            ->assertOk()->assertJsonPath('data.assigned_to', $tech->id);

        $this->actingAs($doctor);
        Livewire::test(OrderDetail::class, ['visitId' => $visit->id])
            ->call('open', $visit->id, $lab->id, true)
            ->set('editNotes', 'Fasting, 10 hours')
            ->call('saveOrder')
            ->assertHasNoErrors();
        $this->assertSame('Fasting, 10 hours', $lab->fresh()->notes);
        $this->assertSame($tech->id, (int) $lab->fresh()->assigned_to);
    }

    public function test_the_api_edits_once_per_key_and_never_another_hospitals_order(): void
    {
        $crown = $this->service('Crown', '300000.00');
        $order = $this->charged($this->service('Filling', '40000.00'));
        Sanctum::actingAs($this->dentist);

        $key = (string) Str::uuid();
        $first = $this->patchJson("/api/v1/orders/{$order->uuid}", ['service_id' => $crown->id, 'quantity' => 1], ['Idempotency-Key' => $key])
            ->assertOk()->assertJsonPath('data.title', 'Crown')->assertJsonPath('data.total', '300000.00')
            ->assertJsonPath('data.can_edit', true)->assertJsonPath('message', 'Order updated.');
        $again = $this->patchJson("/api/v1/orders/{$order->uuid}", ['service_id' => $crown->id, 'quantity' => 1], ['Idempotency-Key' => $key])->assertOk();
        $this->assertSame($first->json(), $again->json());
        $this->assertSame(2, $order->items()->count(), 'one swap: the old line cancelled, one new line');

        $this->patchJson("/api/v1/orders/{$order->uuid}", ['assigned_to' => $this->staff('nurse')->id])
            ->assertStatus(422)->assertJsonValidationErrors(['assigned_to'], 'errors');

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirDoctor = $this->staff('doctor', [], $other);
        $theirOrder = app(OrderService::class)->place($this->visitFor($other, $theirDoctor), OrderType::Procedure, 'Theirs', [], $theirDoctor->id);
        app(CurrentHospital::class)->set($this->h->id);
        $this->assertSame($other->id, (int) $theirOrder->hospital_id);
        $this->patchJson("/api/v1/orders/{$theirOrder->uuid}", ['notes' => 'mine now'])->assertNotFound();
        $this->assertNull(Order::withoutGlobalScopes()->find($theirOrder->id)->notes);
    }

    public function test_a_stays_doctor_and_reason_follow_its_order(): void
    {
        $general = Hospital::factory()->create();
        app(CurrentHospital::class)->set($general->id);
        $doctor = $this->staff('doctor', [], $general);
        $other = $this->staff('doctor', [], $general);
        $visit = $this->visitFor($general, $doctor);
        $ward = \App\Models\Ward::factory()->create(['hospital_id' => $general->id]);
        $bed = \App\Models\Bed::factory()->create(['hospital_id' => $general->id, 'ward_id' => $ward->id]);
        $this->actingAs($doctor);
        app(OrderDesk::class)->place($visit, OrderType::Admission, ['title' => 'Observation', 'bed_id' => $bed->id, 'assigned_to' => $doctor->id, 'notes' => 'Fever'], $doctor);

        $order = Order::query()->where('type', OrderType::Admission->value)->firstOrFail();
        app(OrderDesk::class)->edit($order, ['assigned_to' => $other->id, 'notes' => 'Fever and vomiting'], $doctor);

        $stay = $order->fresh()->subject;
        $this->assertInstanceOf(\App\Models\Admission::class, $stay);
        $this->assertSame($other->id, (int) $stay->admitting_doctor_id);
        $this->assertSame('Fever and vomiting', $stay->reason);
    }

    public function test_a_planned_procedures_price_stays_the_plans_and_odd_numbers_are_refused(): void
    {
        $extraction = $this->service('Difficult extraction', '50000.00', '80000.00');
        $dental = app(DentalService::class);
        $item = $dental->planItem($this->visit->patient, $extraction, 38, null, 65000, null, null, $this->dentist);
        $dental->transition($item, DentalTreatmentStatus::Done, $this->dentist, $this->visit);
        $order = Order::query()->where('subject_type', $item->getMorphClass())->firstOrFail();
        $line = $order->items()->firstOrFail();

        Sanctum::actingAs($this->dentist);
        $this->patchJson("/api/v1/orders/{$order->uuid}", ['lines' => [['id' => $line->id, 'quantity' => '1', 'unit_price' => '70000']]])
            ->assertStatus(422)->assertJsonPath('message', 'This charge comes from another record, so its price is changed there.');
        $this->patchJson("/api/v1/orders/{$order->uuid}", ['lines' => [['id' => $line->id, 'quantity' => '1e3']]])
            ->assertStatus(422)->assertJsonValidationErrors(['lines.0.quantity'], 'errors');
        $this->assertSame('65000.00', (string) $line->fresh()->line_total);

        $this->visit->forceFill(['status' => 'completed', 'outcome' => 'cancelled'])->save();
        $this->getJson("/api/v1/orders/{$order->uuid}")->assertOk()->assertJsonPath('data.can_edit', false);
    }

    // ── On the web ───────────────────────────────────────────────────────

    public function test_the_list_offers_edit_and_the_dialog_saves_the_change(): void
    {
        $other = $this->staff('doctor');
        $crown = $this->service('Crown', '300000.00');
        $order = $this->charged($this->service('Filling', '40000.00'));
        $this->actingAs($this->dentist);

        Livewire::test(Orders::class, ['visitId' => $this->visit->id])
            ->assertSee('Edit Filling')
            ->call('edit', $order->id)
            ->assertDispatched('order-open', visitId: $this->visit->id, orderId: $order->id, edit: true);

        Livewire::test(OrderDetail::class, ['visitId' => $this->visit->id])
            ->call('open', $this->visit->id, $order->id, true)
            ->assertSet('editingOrder', true)
            ->assertSet('editTitle', 'Filling')
            ->assertSee('Dentist assigned')
            ->assertSee('Price list item')
            ->call('picked', 'editServiceId', $crown->id)
            ->call('picked', 'editAssignee', $other->id)
            ->set('editNotes', 'Lower right')
            ->call('saveOrder')
            ->assertHasNoErrors()
            ->assertSet('editingOrder', false)
            ->assertDispatched('toast', type: 'success');

        $order->refresh();
        $this->assertSame('Crown', $order->title);
        $this->assertSame($other->id, (int) $order->assigned_to);
        $this->assertSame('Lower right', $order->notes);
    }

    public function test_the_dialog_refuses_someone_who_does_not_see_patients(): void
    {
        $order = $this->charged($this->service('Filling', '40000.00'));
        $this->actingAs($this->dentist);

        Livewire::test(OrderDetail::class, ['visitId' => $this->visit->id])
            ->call('open', $this->visit->id, $order->id, true)
            ->set('editAssignee', $this->staff('receptionist')->id)
            ->call('saveOrder')
            ->assertHasErrors('editAssignee');
    }

    // ── The dental plan still bills, and its order can be reassigned ──────

    public function test_a_planned_procedure_bills_and_its_order_keeps_its_charge(): void
    {
        $filling = $this->service('Filling', '40000.00');
        $dental = app(DentalService::class);
        $item = $dental->planItem($this->visit->patient, $filling, 36, 'MO', null, null, null, $this->dentist);
        $dental->transition($item, DentalTreatmentStatus::Done, $this->dentist, $this->visit);

        $order = Order::query()->where('subject_type', $item->getMorphClass())->firstOrFail();
        $other = $this->staff('doctor');
        $this->actingAs($this->dentist);

        app(OrderDesk::class)->edit($order, ['assigned_to' => $other->id], $this->dentist);
        $this->assertSame($other->id, (int) $order->fresh()->assigned_to);

        try {
            app(OrderDesk::class)->edit($order, ['service_id' => $this->service('Crown', '300000.00')->id], $this->dentist);
            $this->fail('a planned procedure lost its charge');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('raised from another record', $e->getMessage());
        }
        $this->assertSame('40000.00', app(BillingService::class)->totalsFor($this->visit->fresh())['subtotal']);
    }
}
