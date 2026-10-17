<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientMessage;
use App\Models\Visit;
use App\Models\VisitDiagnosis;
use App\Support\CurrentHospital;
use Database\Seeders\DemoScenarios\Diagnoses;
use Database\Seeders\DemoScenarios\PatientMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The demo stories for coded diagnoses and messages: they fill the screens and run twice safely. */
class DemoScenariosTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnoses_and_messages_scenarios(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        foreach (['Uncomplicated malaria, confirmed by RDT', 'Hypertension, adequately controlled', 'Acute gastroenteritis with mild dehydration', 'Peptic ulcer disease', 'Asthma', 'Type 2 diabetes'] as $i => $dx) {
            $p = Patient::factory()->create(['hospital_id' => $h->id, 'phone_1' => '07011159'.(40 + $i)]);
            Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id, 'diagnosis' => $dx]);
        }

        foreach ([1, 2] as $run) {
            Diagnoses::run($h);
            PatientMessages::run($h);
        }

        $this->assertSame(2, VisitDiagnosis::query()->distinct()->count('visit_id'), 'the newest four are left for the worklist');
        $this->assertGreaterThan(0, PatientMessage::query()->count());
        $this->assertTrue(\App\Services\Messaging\MessagingSettings::of($h->fresh())->smsEnabled());
    }
}
