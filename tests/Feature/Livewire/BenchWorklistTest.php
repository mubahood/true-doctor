<?php

namespace Tests\Feature\Livewire;

use App\Enums\LabOrderStatus;
use App\Enums\RadiologyOrderStatus;
use App\Livewire\LabOrders\Index as LabBench;
use App\Livewire\RadiologyOrders\Index as RadiologyBench;
use App\Models\Hospital;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\Patient;
use App\Models\RadiologyOrder;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The lab and radiology benches.
 *
 * Both were flat lists behind an eye icon: every result took two page loads to
 * enter, nothing said how long anything had been waiting, and — the part that
 * mattered most — THERE WAS NOWHERE TO PUT THE FILE. The analyser's printout
 * and the X-ray film, the two documents a hospital most needs to keep, had no
 * home, because the attachment store pointed at visit orders and nothing else.
 */
class BenchWorklistTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private Patient $patient;

    private \App\Models\Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('local');

        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00'));

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);

        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $this->visit = \App\Models\Visit::factory()->create([
            'hospital_id' => $this->hospital->id, 'patient_id' => $this->patient->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function labOrder(LabOrderStatus $status = LabOrderStatus::Collected, ?string $at = null): LabOrder
    {
        $order = LabOrder::create([
            'uuid' => (string) Str::uuid(),
            'hospital_id' => $this->hospital->id,
            'visit_id' => $this->visit->id,
            'patient_id' => $this->patient->id,
            'status' => $status,
        ]);

        if ($at !== null) {
            $order->forceFill(['created_at' => Carbon::parse($at)])->save();
        }

        LabOrderItem::create([
            'hospital_id' => $this->hospital->id,
            'lab_order_id' => $order->id,
            'name' => 'Haemoglobin',
            'price' => '10000',
        ]);

        return $order->fresh();
    }

    private function radiologyOrder(RadiologyOrderStatus $status = RadiologyOrderStatus::Performed): RadiologyOrder
    {
        return RadiologyOrder::create([
            'uuid' => (string) Str::uuid(),
            'hospital_id' => $this->hospital->id,
            'visit_id' => $this->visit->id,
            'patient_id' => $this->patient->id,
            'status' => $status,
        ]);
    }

    // ── One store, three kinds of work ───────────────────────────────────

    /**
     * The point of the whole rebuild: a lab result PDF now has somewhere to go.
     */
    public function test_a_lab_order_can_hold_the_file_the_analyser_printed(): void
    {
        $order = $this->labOrder();

        Livewire::test(LabBench::class)
            ->call('openResult', $order->id)
            ->set('files', [UploadedFile::fake()->create('haemoglobin.pdf', 80, 'application/pdf')]);

        $file = $order->fresh()->attachments()->firstOrFail();

        $this->assertSame('haemoglobin.pdf', $file->original_name);
        $this->assertSame(LabOrder::class, $file->attachable_type);
        Storage::disk('local')->assertExists($file->file_path);
    }

    public function test_a_radiology_order_can_hold_its_films(): void
    {
        $order = $this->radiologyOrder();

        Livewire::test(RadiologyBench::class)
            ->call('openReport', $order->id)
            ->set('files', [
                UploadedFile::fake()->image('chest-pa.jpg'),
                UploadedFile::fake()->image('chest-lat.jpg'),
            ]);

        $this->assertCount(2, $order->fresh()->attachments);
    }

    /** …and the visit module's own orders are untouched by the move. */
    public function test_a_visit_order_still_holds_its_own(): void
    {
        $order = Order::create([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $this->hospital->id,
            'visit_id' => $this->visit->id, 'patient_id' => $this->patient->id,
            'type' => \App\Enums\OrderType::Lab, 'status' => \App\Enums\OrderStatus::Pending,
            'title' => 'Full blood count',
        ]);

        app(\App\Services\OrderAttachmentService::class)
            ->store($order, UploadedFile::fake()->create('result.pdf', 20, 'application/pdf'));

        $this->assertCount(1, $order->fresh()->attachments);
        $this->assertSame(Order::class, $order->fresh()->attachments->first()->attachable_type);
    }

    /** Three kinds of order numbered 7 must not share a folder. */
    public function test_each_kind_of_work_files_into_its_own_folder(): void
    {
        $lab = $this->labOrder();

        Livewire::test(LabBench::class)
            ->call('openResult', $lab->id)
            ->set('files', [UploadedFile::fake()->create('r.pdf', 10, 'application/pdf')]);

        $this->assertStringStartsWith('lab_orders/', $lab->fresh()->attachments()->firstOrFail()->file_path);
    }

    public function test_a_file_can_be_taken_off_again(): void
    {
        $order = $this->labOrder();

        $page = Livewire::test(LabBench::class)
            ->call('openResult', $order->id)
            ->set('files', [UploadedFile::fake()->create('wrong.pdf', 10, 'application/pdf')]);

        $file = $order->fresh()->attachments()->firstOrFail();

        $page->call('removeAttachment', $file->id);

        $this->assertCount(0, $order->fresh()->attachments);
        Storage::disk('local')->assertMissing($file->file_path);
    }

    /** An id from somebody else's record is a no-op, not a deletion. */
    public function test_a_file_belonging_to_another_order_cannot_be_removed(): void
    {
        $mine = $this->labOrder();
        $theirs = $this->labOrder();

        Livewire::test(LabBench::class)
            ->call('openResult', $theirs->id)
            ->set('files', [UploadedFile::fake()->create('theirs.pdf', 10, 'application/pdf')]);

        $file = $theirs->fresh()->attachments()->firstOrFail();

        Livewire::test(LabBench::class)
            ->call('openResult', $mine->id)
            ->call('removeAttachment', $file->id);

        $this->assertCount(1, $theirs->fresh()->attachments, 'it must still be there');
    }

    public function test_something_that_is_not_a_result_is_refused(): void
    {
        $order = $this->labOrder();

        Livewire::test(LabBench::class)
            ->call('openResult', $order->id)
            ->set('files', [UploadedFile::fake()->create('macro.exe', 10, 'application/octet-stream')])
            ->assertHasErrors('files.0');

        $this->assertCount(0, $order->fresh()->attachments);
    }

    // ── Entering the result, without leaving the list ────────────────────

    public function test_results_are_entered_and_saved_from_the_worklist(): void
    {
        $order = $this->labOrder();
        $item = $order->items->firstOrFail();

        Livewire::test(LabBench::class)
            ->call('openResult', $order->id)
            ->assertSet('showResult', true)
            ->set("results.{$item->id}.result_value", '11.2')
            ->set("results.{$item->id}.result_flag", 'low')
            ->call('saveResults')
            ->assertHasNoErrors();

        $fresh = $item->fresh();
        $this->assertSame('11.2', $fresh->result_value);
        $this->assertSame('low', $fresh->result_flag?->value);
    }

    public function test_saving_and_reporting_back_is_one_press(): void
    {
        $order = $this->labOrder(LabOrderStatus::Processing);
        $item = $order->items->firstOrFail();

        Livewire::test(LabBench::class)
            ->call('openResult', $order->id)
            ->set("results.{$item->id}.result_value", '11.2')
            ->call('completeOrder')
            ->assertSet('showResult', false);

        $this->assertSame('11.2', $item->fresh()->result_value);
        $this->assertSame(LabOrderStatus::Completed, $order->fresh()->status);
    }

    public function test_the_radiology_report_is_written_from_the_worklist(): void
    {
        $order = $this->radiologyOrder();

        Livewire::test(RadiologyBench::class)
            ->call('openReport', $order->id)
            ->set('findings', 'Clear lung fields. No effusion.')
            ->set('impression', 'Normal chest radiograph.')
            ->call('saveReport')
            ->assertHasNoErrors();

        $fresh = $order->fresh();
        $this->assertSame('Clear lung fields. No effusion.', $fresh->findings);
        $this->assertSame('Normal chest radiograph.', $fresh->impression);
    }

    public function test_signing_off_writes_the_report_and_moves_it(): void
    {
        $order = $this->radiologyOrder();

        Livewire::test(RadiologyBench::class)
            ->call('openReport', $order->id)
            ->set('findings', 'Clear lung fields.')
            ->call('signOff')
            ->assertSet('showReport', false);

        $fresh = $order->fresh();
        $this->assertSame(RadiologyOrderStatus::Reported, $fresh->status);
        $this->assertSame('Clear lung fields.', $fresh->findings);
    }

    public function test_reopening_shows_what_is_already_written(): void
    {
        $order = $this->radiologyOrder();
        $order->update(['findings' => 'Already written.']);

        Livewire::test(RadiologyBench::class)->call('openReport', $order->id)
            ->assertSet('findings', 'Already written.');
    }

    // ── What the bench owes back ─────────────────────────────────────────

    public function test_the_headline_counts_what_is_outstanding(): void
    {
        $this->labOrder(LabOrderStatus::Ordered);
        $this->labOrder(LabOrderStatus::Collected);
        $this->labOrder(LabOrderStatus::Collected);

        $waiting = Livewire::test(LabBench::class)->instance()->waiting();

        $this->assertSame(1, $waiting['ordered']);
        $this->assertSame(2, $waiting['collected']);
    }

    /** A count of orders is not a workload; the oldest one waiting is. */
    public function test_the_oldest_outstanding_order_is_surfaced(): void
    {
        $this->labOrder(LabOrderStatus::Collected, '2026-09-16 12:00');
        $this->labOrder(LabOrderStatus::Collected, '2026-09-18 10:00');

        $this->assertSame(48, Livewire::test(LabBench::class)->instance()->waiting()['oldest']);
    }

    public function test_an_order_sitting_too_long_is_flagged_on_its_row(): void
    {
        $old = $this->labOrder(LabOrderStatus::Collected, '2026-09-16 12:00');
        $fresh = $this->labOrder(LabOrderStatus::Collected, '2026-09-18 11:00');

        $page = Livewire::test(LabBench::class)->instance();

        $this->assertTrue($page->isOverdue($old->fresh()));
        $this->assertFalse($page->isOverdue($fresh->fresh()));
    }

    public function test_outstanding_only_hides_what_is_finished(): void
    {
        $this->labOrder(LabOrderStatus::Collected);
        $done = $this->labOrder(LabOrderStatus::Completed);

        Livewire::test(LabBench::class)
            ->set('outstanding', true)
            ->assertDontSee($done->uuid);
    }

    public function test_a_hand_typed_sort_column_is_refused(): void
    {
        Livewire::test(LabBench::class)->call('sortBy', 'hospital_id')->assertSet('sortField', '');
        Livewire::test(LabBench::class)->call('sortBy', 'created_at')->assertSet('sortField', 'created_at');
    }

    // ── What the work used ───────────────────────────────────────────────

    /**
     * A test is not only its own fee: it uses reagents, tubes, sometimes a
     * repeat run. The bench could record none of it, so the hospital did the
     * work and billed the list price whatever it actually cost.
     */
    public function test_a_service_used_on_the_bench_is_charged_to_the_same_order(): void
    {
        $order = $this->labOrder();
        $service = \App\Models\Service::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Urgent processing', 'price' => '8000.00',
        ]);

        Livewire::test(LabBench::class)
            ->call('openResult', $order->id)
            ->call('picked', 'provided_service_id', $service->id)
            ->call('saveResults')
            ->assertHasNoErrors();

        // Onto the order the tests were billed to, not a second one.
        $billing = $this->billingOrderFor($order);
        $this->assertNotNull($billing);
        $this->assertTrue($billing->items->contains('name', 'Urgent processing'));
    }

    public function test_a_product_off_the_shelf_is_charged_and_deducted(): void
    {
        $order = $this->labOrder();
        $tube = \App\Models\StockItem::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'EDTA tube',
            'sale_price' => '1500.00', 'current_quantity' => '50', 'is_active' => true,
        ]);

        Livewire::test(LabBench::class)
            ->call('openResult', $order->id)
            ->call('picked', 'provided_product_id', $tube->id)
            ->set('provided.0.quantity', '3')
            ->call('saveResults')
            ->assertHasNoErrors();

        $this->assertSame(0, bccomp((string) $tube->fresh()->current_quantity, '47', 2), 'the shelf must go down');
    }

    public function test_radiology_charges_what_the_study_used(): void
    {
        $order = $this->radiologyOrder();
        $contrast = \App\Models\Service::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Contrast', 'price' => '25000.00',
        ]);

        Livewire::test(RadiologyBench::class)
            ->call('openReport', $order->id)
            ->call('picked', 'provided_service_id', $contrast->id)
            ->set('findings', 'Normal.')
            ->call('saveReport')
            ->assertHasNoErrors();

        $this->assertTrue($this->billingOrderFor($order)->items->contains('name', 'Contrast'));
    }

    /** Reopening shows what was already charged, not a blank list over it. */
    public function test_reopening_shows_what_was_already_used(): void
    {
        $order = $this->labOrder();
        $service = \App\Models\Service::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Urgent processing', 'price' => '8000.00',
        ]);

        Livewire::test(LabBench::class)
            ->call('openResult', $order->id)
            ->call('picked', 'provided_service_id', $service->id)
            ->call('saveResults');

        $again = Livewire::test(LabBench::class)->call('openResult', $order->id);

        $this->assertCount(1, $again->get('provided'));
        $this->assertSame('Urgent processing', $again->get('provided')[0]['name']);
    }

    public function test_taking_a_line_off_takes_it_off_the_bill(): void
    {
        $order = $this->labOrder();
        $service = \App\Models\Service::factory()->create([
            'hospital_id' => $this->hospital->id, 'name' => 'Urgent processing', 'price' => '8000.00',
        ]);

        $page = Livewire::test(LabBench::class)
            ->call('openResult', $order->id)
            ->call('picked', 'provided_service_id', $service->id)
            ->call('saveResults');

        $page->call('removeProvided', 0)->call('saveResults');

        $live = $this->billingOrderFor($order)->items
            ->reject(fn ($i) => $i->status === \App\Enums\OrderItemStatus::Cancelled)
            ->filter(fn ($i) => $i->name === 'Urgent processing');

        $this->assertCount(0, $live);
    }

    public function test_a_fractional_quantity_is_refused(): void
    {
        $order = $this->labOrder();
        $service = \App\Models\Service::factory()->create(['hospital_id' => $this->hospital->id]);

        Livewire::test(LabBench::class)
            ->call('openResult', $order->id)
            ->call('picked', 'provided_service_id', $service->id)
            ->set('provided.0.quantity', '1.5')
            ->call('saveResults')
            ->assertHasErrors('provided.0.quantity');
    }

    /** One question, asked the same way wherever work is recorded. */
    public function test_every_screen_that_records_work_charges_it_the_same_way(): void
    {
        foreach ([LabBench::class, RadiologyBench::class, \App\Livewire\Appointments\Index::class] as $component) {
            $this->assertContains(
                \App\Livewire\Concerns\ChargesWorkDone::class,
                class_uses_recursive($component),
                $component.' has its own idea of what "what did this use" means',
            );
        }
    }

    /** The order a piece of work is billed through — placed when it was ordered. */
    private function billingOrderFor(LabOrder|RadiologyOrder $work): ?Order
    {
        return Order::with('items')
            ->where('subject_type', $work::class)
            ->where('subject_id', $work->id)
            ->latest('id')
            ->first();
    }

    // ── Who may ──────────────────────────────────────────────────────────

    /** Reading the worklist is not writing a result. */
    public function test_somebody_who_may_only_look_cannot_file_a_result(): void
    {
        $order = $this->labOrder();

        $doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();
        $this->actingAs($doctor);

        Livewire::test(LabBench::class)->assertOk();
        Livewire::test(LabBench::class)
            ->call('openResult', $order->id)
            ->set('files', [UploadedFile::fake()->create('r.pdf', 10, 'application/pdf')])
            ->assertForbidden();

        $this->assertCount(0, $order->fresh()->attachments);
    }

    public function test_another_hospitals_order_cannot_be_opened(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirPatient = Patient::factory()->create(['hospital_id' => $other->id]);
        $theirs = LabOrder::create([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $other->id,
            'visit_id' => \App\Models\Visit::factory()->create([
                'hospital_id' => $other->id, 'patient_id' => $theirPatient->id,
            ])->id,
            'patient_id' => $theirPatient->id,
            'status' => LabOrderStatus::Collected,
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(LabBench::class)->call('openResult', $theirs->id);
    }

    // ── The file itself is PHI ───────────────────────────────────────────

    public function test_a_result_is_streamed_behind_a_permission_not_a_public_url(): void
    {
        $order = $this->labOrder();

        Livewire::test(LabBench::class)
            ->call('openResult', $order->id)
            ->set('files', [UploadedFile::fake()->create('result.pdf', 10, 'application/pdf')]);

        $file = $order->fresh()->attachments()->firstOrFail();
        $url = route('admin.lab-orders.attachments.download', [$order, $file]);

        $this->get($url)->assertOk();

        $receptionist = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $receptionist->syncSpatieRole();
        $this->actingAs($receptionist)->get($url)->assertForbidden();
    }

    public function test_a_file_cannot_be_fetched_through_the_wrong_order(): void
    {
        $mine = $this->labOrder();
        $theirs = $this->labOrder();

        Livewire::test(LabBench::class)
            ->call('openResult', $theirs->id)
            ->set('files', [UploadedFile::fake()->create('theirs.pdf', 10, 'application/pdf')]);

        $file = $theirs->fresh()->attachments()->firstOrFail();

        $this->get(route('admin.lab-orders.attachments.download', [$mine, $file]))->assertNotFound();
    }

    /** One trait, so a third bench cannot grow its own idea of uploading. */
    public function test_both_benches_share_one_way_of_collecting_files(): void
    {
        foreach ([LabBench::class, RadiologyBench::class] as $component) {
            $this->assertContains(
                \App\Livewire\Concerns\CollectsAttachments::class,
                class_uses_recursive($component),
                $component.' has its own idea of how a file is filed',
            );
        }
    }

    public function test_every_kind_of_work_that_holds_files_says_so(): void
    {
        foreach ([Order::class, LabOrder::class, RadiologyOrder::class] as $model) {
            $this->assertContains(
                \App\Models\Contracts\HoldsAttachments::class,
                class_implements($model),
                $model.' holds attachments without declaring it',
            );
        }
    }

    /** Nothing was left behind by the move to one store. */
    public function test_the_old_single_owner_table_is_gone(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('order_attachments'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('attachments'));
        $this->assertSame('attachments', (new OrderAttachment)->getTable());
    }
}
