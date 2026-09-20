<?php

namespace Tests\Unit;

use App\Models\Hospital;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Unit\Fixtures\TenancyScopeProbe;

/**
 * HMS_PLAN.md §2.1 constraint B8: single enforced tenancy, no manual
 * per-query filtering. These prove the BelongsToHospital mechanism itself —
 * every real tenant model (Patient, Appointment, ... from Phase 1 onward)
 * inherits this behaviour for free by using the trait.
 */
class BelongsToHospitalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenancy_scope_probes', function ($table) {
            $table->id();
            $table->foreignId('hospital_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tenancy_scope_probes');
        parent::tearDown();
    }

    public function test_records_are_scoped_to_the_current_hospital(): void
    {
        $hospitalA = Hospital::factory()->create();
        $hospitalB = Hospital::factory()->create();

        app(CurrentHospital::class)->set($hospitalA->id);
        TenancyScopeProbe::create(['name' => 'from A']);

        app(CurrentHospital::class)->set($hospitalB->id);
        TenancyScopeProbe::create(['name' => 'from B']);

        app(CurrentHospital::class)->set($hospitalA->id);
        $this->assertSame(['from A'], TenancyScopeProbe::pluck('name')->all());

        app(CurrentHospital::class)->set($hospitalB->id);
        $this->assertSame(['from B'], TenancyScopeProbe::pluck('name')->all());
    }

    public function test_hospital_a_cannot_read_or_write_hospital_bs_row_by_id(): void
    {
        $hospitalA = Hospital::factory()->create();
        $hospitalB = Hospital::factory()->create();

        app(CurrentHospital::class)->set($hospitalB->id);
        $bsRecord = TenancyScopeProbe::create(['name' => 'B secret']);

        app(CurrentHospital::class)->set($hospitalA->id);
        $this->assertNull(TenancyScopeProbe::find($bsRecord->id));

        $updated = TenancyScopeProbe::whereKey($bsRecord->id)->update(['name' => 'tampered']);
        $this->assertSame(0, $updated, 'hospital A must not be able to write to hospital Bs row');

        $deleted = TenancyScopeProbe::whereKey($bsRecord->id)->delete();
        $this->assertSame(0, $deleted, 'hospital A must not be able to delete hospital Bs row');
    }

    public function test_hospital_id_is_auto_filled_from_the_current_context_on_create(): void
    {
        $hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($hospital->id);

        $probe = TenancyScopeProbe::create(['name' => 'auto-filled']);

        $this->assertSame($hospital->id, $probe->hospital_id);
    }

    public function test_no_current_hospital_context_is_unscoped_not_hidden(): void
    {
        $hospitalA = Hospital::factory()->create();
        app(CurrentHospital::class)->set($hospitalA->id);
        TenancyScopeProbe::create(['name' => 'from A']);

        app(CurrentHospital::class)->set(null);

        $this->assertSame(['from A'], TenancyScopeProbe::pluck('name')->all());
    }
}
