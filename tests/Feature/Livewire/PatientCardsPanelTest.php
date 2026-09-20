<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Patients\Panels\Cards;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientCard;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The prepaid-card panel of the patient workspace (ported from the retired
 * PatientCardFlowTest, which drove PatientCardController over HTTP): issue →
 * credit → debit, the overdraft rejection as an inline error, RBAC and tenancy.
 * The money math itself is covered unit-style by CardTransactionTest.
 */
class PatientCardsPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function acting(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    private function panel(Patient $patient)
    {
        Livewire::withoutLazyLoading();

        return Livewire::test(Cards::class, ['patientId' => $patient->id]);
    }

    public function test_issue_credit_and_debit_flow(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)
            ->call('openIssue')
            ->set('accepts_credit', false)
            ->call('issue')
            ->assertHasNoErrors();

        $card = PatientCard::where('patient_id', $patient->id)->firstOrFail();

        $this->panel($patient)
            ->call('openTxn', $card->id, 'credit')
            ->set('amount', '150.00')
            ->set('description', 'Deposit')
            ->call('postTxn')
            ->assertHasNoErrors();
        $this->assertSame('150.00', (string) $card->fresh()->balance);

        $this->panel($patient)
            ->call('openTxn', $card->id, 'debit')
            ->set('amount', '40.00')
            ->set('description', 'Consult')
            ->call('postTxn')
            ->assertHasNoErrors();
        $this->assertSame('110.00', (string) $card->fresh()->balance);

        $this->assertDatabaseCount('card_records', 2);
    }

    public function test_overdraft_debit_is_rejected_with_an_inline_error(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)->call('openIssue')->call('issue')->assertHasNoErrors();
        $card = PatientCard::where('patient_id', $patient->id)->firstOrFail();

        $this->panel($patient)
            ->call('openTxn', $card->id, 'debit')
            ->set('amount', '10.00')
            ->call('postTxn')
            ->assertHasErrors('amount')
            ->assertSet('showTxn', true);

        $this->assertSame('0.00', (string) $card->fresh()->balance);
        $this->assertDatabaseCount('card_records', 0);
    }

    public function test_a_zero_amount_is_rejected_by_validation(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)->call('openIssue')->call('issue');
        $card = PatientCard::where('patient_id', $patient->id)->firstOrFail();

        $this->panel($patient)
            ->call('openTxn', $card->id, 'credit')
            ->set('amount', '0')
            ->call('postTxn')
            ->assertHasErrors('amount');

        $this->assertDatabaseCount('card_records', 0);
    }

    public function test_a_role_without_card_permission_cannot_open_the_panel(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'doctor'); // patients.view, no patients.card.manage
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($patient)->assertForbidden();
        $this->assertDatabaseCount('patient_cards', 0);
    }

    public function test_a_patient_of_another_hospital_cannot_be_reached(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $patientB = Patient::factory()->create(['hospital_id' => $b->id]);

        // Issue B's card as B's receptionist.
        $this->acting($b, 'receptionist');
        $this->panel($patientB)->call('openIssue')->call('issue');
        $cardB = PatientCard::withoutGlobalScopes()->where('patient_id', $patientB->id)->firstOrFail();

        // A's receptionist cannot mount the panel for B's patient at all.
        $this->acting($a, 'receptionist');
        $this->expectException(ModelNotFoundException::class);

        try {
            $this->panel($patientB);
        } finally {
            $this->assertSame('0.00', (string) $cardB->fresh()->balance);
        }
    }

    public function test_a_card_of_another_patient_cannot_be_charged_through_this_panel(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $owner = Patient::factory()->create(['hospital_id' => $h->id]);
        $other = Patient::factory()->create(['hospital_id' => $h->id]);

        $this->panel($owner)->call('openIssue')->call('issue');
        $card = PatientCard::where('patient_id', $owner->id)->firstOrFail();

        $this->expectException(ModelNotFoundException::class);
        $this->panel($other)->call('openTxn', $card->id, 'credit');
    }
}
