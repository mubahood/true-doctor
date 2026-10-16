<?php

namespace Tests\Feature\Api;

use App\Models\CardRecord;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\Patient;
use App\Models\User;
use App\Services\CardService;
use App\Services\Finance\FinancialStatements;
use App\Services\InsuranceLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/** Cards and the card ledger over the API: patients.card.manage, CardService for every cent. */
class CardApiTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Hospital $hospital;

    private function as(string $role, ?Hospital $h = null): User
    {
        $this->seedRbac();
        $h ??= $this->hospital;
        $this->withHospital($h);
        $user = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $user->syncSpatieRole();
        Sanctum::actingAs($user);

        return $user;
    }

    private function patient(?Hospital $h = null): Patient
    {
        return Patient::factory()->create(['hospital_id' => ($h ?? $this->hospital)->id]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->hospital = $this->hospital(['currency' => 'UGX']);
        $this->withHospital($this->hospital);
    }

    public function test_issue_top_up_charge_refuse_overdraft_and_the_ledger_proves_the_balance(): void
    {
        $this->as('receptionist');
        $patient = $this->patient();

        $issued = $this->postJson("/api/v1/patients/{$patient->uuid}/cards", ['expiry' => now()->addYear()->toDateString()])->assertCreated();
        $issued->assertJsonPath('data.balance', '0.00')->assertJsonPath('data.status', 'active');
        $card = $issued->json('data');
        $this->assertArrayNotHasKey('card_number', $card, 'the number never leaves the server');
        $this->assertMatchesRegularExpression('/^•••• \d{4}$/', $card['masked']);

        $this->postJson("/api/v1/cards/{$card['uuid']}/transactions", ['type' => 'credit', 'amount' => '50000', 'paid_by' => 'mobile_money', 'description' => 'Top-up'])
            ->assertCreated()->assertJsonPath('data.card.balance', '50000.00')->assertJsonPath('data.record.balance_after', '50000.00');
        $this->postJson("/api/v1/cards/{$card['uuid']}/transactions", ['type' => 'debit', 'amount' => '12000.50'])
            ->assertCreated()->assertJsonPath('data.card.balance', '37999.50');
        // No overdraft on a card that does not take credit, no gateway methods, no float amounts.
        $this->postJson("/api/v1/cards/{$card['uuid']}/transactions", ['type' => 'debit', 'amount' => '999999'])->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson("/api/v1/cards/{$card['uuid']}/transactions", ['type' => 'credit', 'amount' => '10', 'paid_by' => 'flutterwave'])->assertStatus(422)->assertJsonValidationErrors(['paid_by']);
        $this->postJson("/api/v1/cards/{$card['uuid']}/transactions", ['type' => 'refund', 'amount' => '10'])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->postJson("/api/v1/cards/{$card['uuid']}/transactions", ['type' => 'credit', 'amount' => '1.234'])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $show = $this->getJson("/api/v1/cards/{$card['uuid']}")->assertOk();
        $show->assertJsonPath('data.balance', '37999.50')->assertJsonPath('data.proof.agrees', true)->assertJsonPath('data.proof.ledger', '37999.50');

        $this->getJson("/api/v1/cards/{$card['uuid']}/records")->assertOk()->assertJsonPath('meta.total', 2);

        // The deposit is a liability in the books, posted by the same CardService code.
        $this->assertSame('37999.50', app(FinancialStatements::class)->balances(Carbon::now())[\App\Models\LedgerAccount::where('system_key', 'card_deposits')->value('id')]);

        $this->getJson("/api/v1/patients/{$patient->uuid}/cards")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_list_filters_totals_and_the_hospital_wide_ledger(): void
    {
        $this->as('accountant');
        $cards = app(CardService::class);
        $a = $cards->issue($this->patient(), []);
        $b = $cards->issue($this->patient(), []);
        $insurer = InsuranceProvider::create(['name' => 'Jubilee', 'is_active' => true]);
        $ins = $cards->issue($this->patient(), ['insurance_provider_id' => $insurer->id, 'member_no' => 'MEM-77', 'accepts_credit' => true, 'max_credit' => '100000']);
        $cards->credit($a, '1000.00', 'x');
        $cards->credit($b, '250.00', 'y');
        $cards->debit($ins, '400.00', 'Treatment');

        $list = $this->getJson('/api/v1/cards')->assertOk()->assertJsonPath('meta.total', 3);
        $list->assertJsonPath('meta.totals.cards', 3)->assertJsonPath('meta.totals.held', '1250.00')->assertJsonPath('meta.totals.owed', '400.00');
        $this->getJson('/api/v1/cards?owing=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.member_no', 'MEM-77');
        $this->getJson('/api/v1/cards?insurer=own')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/cards?insurer='.$insurer->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/cards?q=MEM-77')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/cards?sort=balance&dir=asc')->assertJsonPath('data.0.member_no', 'MEM-77');

        $led = $this->getJson('/api/v1/card-records')->assertOk()->assertJsonPath('meta.total', 3);
        $led->assertJsonPath('meta.totals.in', '1250.00')->assertJsonPath('meta.totals.out', '400.00')->assertJsonPath('meta.totals.net', '850.00');
        $this->getJson('/api/v1/card-records?type=debit')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/card-records?insurer=insured')->assertJsonPath('meta.total', 1);
        $uuid = $led->json('data.0.uuid');
        $this->getJson("/api/v1/card-records/{$uuid}")->assertOk()->assertJsonPath('data.uuid', $uuid);
    }

    public function test_lookup_terms_holders_and_settlement(): void
    {
        $this->as('accountant');
        $cards = app(CardService::class);
        $owner = $this->patient();
        $card = $cards->issue($owner, []);
        $number = $card->card_number;

        $this->postJson('/api/v1/cards/lookup', ['number' => $number])->assertOk()->assertJsonPath('data.uuid', $card->uuid);
        $this->postJson('/api/v1/cards/lookup', ['number' => '4000000000000000'])->assertNotFound();
        $this->postJson('/api/v1/cards/lookup', [])->assertStatus(422);

        $this->putJson("/api/v1/cards/{$card->uuid}/terms", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson("/api/v1/cards/{$card->uuid}/transactions", ['type' => 'credit', 'amount' => '10'])->assertStatus(422);
        $this->putJson("/api/v1/cards/{$card->uuid}/terms", ['status' => 'active', 'accepts_credit' => true, 'max_credit' => '5000'])->assertOk()->assertJsonPath('data.max_credit', '5000.00');

        $member = $this->patient();
        $h = $this->postJson("/api/v1/cards/{$card->uuid}/holders", ['patient_uuid' => $member->uuid, 'relationship' => 'child'])->assertCreated()->json('data');
        $this->postJson("/api/v1/cards/{$card->uuid}/holders", ['patient_uuid' => $owner->uuid, 'relationship' => 'child'])->assertStatus(422);
        $this->getJson("/api/v1/cards/{$card->uuid}")->assertJsonCount(1, 'data.holders');
        $this->deleteJson("/api/v1/cards/{$card->uuid}/holders/{$h['id']}")->assertOk()->assertJsonPath('data.status', 'revoked');

        // An insurance card in debt is cleared from the float, by someone who manages insurance.
        $insurer = InsuranceProvider::create(['name' => 'Sanlam', 'is_active' => true]);
        $ins = $cards->issue($this->patient(), ['insurance_provider_id' => $insurer->id, 'accepts_credit' => true, 'max_credit' => '1000']);
        $cards->debit($ins, '300.00', 'Visit');
        app(InsuranceLedgerService::class)->deposit($insurer, '1000.00', 'R1', null);

        $this->postJson("/api/v1/cards/{$ins->uuid}/settle")->assertOk()->assertJsonPath('data.settled', '300.00')->assertJsonPath('data.card.balance', '0.00');
        $this->assertSame('700.00', (string) $insurer->fresh()->float_balance);
        $this->postJson("/api/v1/cards/{$ins->uuid}/settle")->assertOk()->assertJsonPath('data.settled', '0.00');
        $this->assertTrue(app(InsuranceLedgerService::class)->reconcile($insurer->fresh())['agrees']);

        // A card desk (patients.card.manage) without insurance.manage may not spend the float.
        $cards->debit($ins->fresh(), '50.00', 'Visit');
        $this->as('receptionist');
        $this->postJson("/api/v1/cards/{$ins->uuid}/settle")->assertForbidden();
    }

    public function test_roles_without_card_permission_and_other_hospitals_are_kept_out(): void
    {
        $this->as('accountant');
        $mine = app(CardService::class)->issue($this->patient(), []);
        $otherHospital = $this->hospital();
        $this->withHospital($otherHospital);
        $theirPatient = $this->patient($otherHospital);
        $theirs = app(CardService::class)->issue($theirPatient, []);
        CardRecord::query()->count();

        foreach (['doctor', 'nurse'] as $role) {
            $this->as($role);
            $this->getJson('/api/v1/cards')->assertForbidden();
            $this->getJson('/api/v1/card-records')->assertForbidden();
            $this->getJson("/api/v1/cards/{$mine->uuid}")->assertForbidden();
            $this->postJson("/api/v1/cards/{$mine->uuid}/transactions", ['type' => 'credit', 'amount' => '10'])->assertForbidden();
            $this->postJson('/api/v1/cards/lookup', ['number' => $mine->card_number])->assertForbidden();
        }

        $this->as('accountant');
        $this->getJson("/api/v1/cards/{$theirs->uuid}")->assertNotFound();
        $this->postJson("/api/v1/cards/{$theirs->uuid}/transactions", ['type' => 'credit', 'amount' => '10'])->assertNotFound();
        $this->postJson('/api/v1/cards/lookup', ['number' => $theirs->card_number])->assertNotFound();
        $this->postJson("/api/v1/patients/{$theirPatient->uuid}/cards", [])->assertNotFound();
        $this->getJson('/api/v1/cards')->assertJsonPath('meta.total', 1);
        $this->assertSame('0.00', (string) $theirs->fresh()->balance);

        // Another hospital's patient cannot be put on my card.
        $this->postJson("/api/v1/cards/{$mine->uuid}/holders", ['patient_uuid' => $theirPatient->uuid, 'relationship' => 'child'])->assertStatus(422);
    }
}
