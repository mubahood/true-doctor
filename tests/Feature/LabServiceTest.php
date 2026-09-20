<?php

namespace Tests\Feature;

use App\Enums\LabOrderStatus;
use App\Enums\ResultFlag;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\LabService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabServiceTest extends TestCase
{
    use RefreshDatabase;

    private LabService $lab;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lab = app(LabService::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function visit(): Visit
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        return Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
    }

    public function test_ordering_snapshots_tests_and_bills_them(): void
    {
        $c = $this->visit();
        $t1 = LabTest::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'CBC', 'price' => '20.00']);
        $t2 = LabTest::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Malaria', 'price' => '10.00']);

        $order = $this->lab->order($c, [$t1->id, $t2->id], 'Fever workup', null);

        $this->assertSame(2, $order->items()->count());
        $this->assertSame(LabOrderStatus::Ordered, $order->status);
        // Both tests billed on the visit.
        $this->assertSame(2, $c->orderItems()->count());
        $this->assertSame('30.00', number_format((float) $c->orderItems()->sum('line_total'), 2));
    }

    public function test_record_result_and_flag(): void
    {
        $c = $this->visit();
        $t = LabTest::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '20.00']);
        $order = $this->lab->order($c, [$t->id], null, null);
        $item = $order->items()->first();

        $this->lab->recordResult($item, ['result_value' => '13.5', 'result_flag' => 'high', 'result_notes' => 'Elevated'], null);

        $item->refresh();
        $this->assertSame('13.5', $item->result_value);
        $this->assertSame(ResultFlag::High, $item->result_flag);
        $this->assertTrue($item->hasResult());
    }

    public function test_status_machine_and_illegal_jump(): void
    {
        $c = $this->visit();
        $t = LabTest::factory()->create(['hospital_id' => $this->hospital->id]);
        $order = $this->lab->order($c, [$t->id], null, null);

        $this->lab->transition($order, LabOrderStatus::Collected);
        $this->lab->transition($order, LabOrderStatus::Processing);
        $done = $this->lab->transition($order, LabOrderStatus::Completed);
        $this->assertSame(LabOrderStatus::Completed, $done->status);
        $this->assertNotNull($done->completed_at);

        $this->expectException(\RuntimeException::class);
        $this->lab->transition($done, LabOrderStatus::Ordered);
    }

    public function test_skip_ahead_is_rejected(): void
    {
        $c = $this->visit();
        $t = LabTest::factory()->create(['hospital_id' => $this->hospital->id]);
        $order = $this->lab->order($c, [$t->id], null, null);

        $this->expectException(\RuntimeException::class);
        $this->lab->transition($order, LabOrderStatus::Completed); // ordered → completed not allowed
    }
}
