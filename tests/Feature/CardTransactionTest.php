<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Exceptions\InsufficientFundsException;
use App\Models\Hospital;
use App\Models\Patient;
use App\Services\CardService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardTransactionTest extends TestCase
{
    use RefreshDatabase;

    private CardService $cards;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cards = app(CardService::class);
    }

    private function patient(array $overrides = []): Patient
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);

        return Patient::factory()->create(['hospital_id' => $h->id] + $overrides);
    }

    public function test_credit_increases_balance_and_writes_a_ledger_row(): void
    {
        $card = $this->cards->issue($this->patient(), []);

        $rec = $this->cards->credit($card, '100.00', 'Top-up');

        $this->assertSame('100.00', (string) $card->fresh()->balance);
        $this->assertSame('100.00', (string) $rec->balance_after);
        $this->assertDatabaseHas('card_records', ['patient_card_id' => $card->id, 'type' => 'credit', 'amount' => '100.00']);
    }

    public function test_debit_within_balance_decreases_it(): void
    {
        $card = $this->cards->issue($this->patient(), []);
        $this->cards->credit($card, '100.00', null);

        $this->cards->debit($card, '30.00', 'Visit');

        $this->assertSame('70.00', (string) $card->fresh()->balance);
    }

    public function test_debit_beyond_balance_without_credit_is_rejected_atomically(): void
    {
        $card = $this->cards->issue($this->patient(), []);
        $this->cards->credit($card, '50.00', null);

        try {
            $this->cards->debit($card, '80.00', 'Too much');
            $this->fail('Expected InsufficientFundsException');
        } catch (InsufficientFundsException $e) {
            // expected
        }

        $this->assertSame('50.00', (string) $card->fresh()->balance);
        $this->assertSame(1, $card->records()->count()); // only the credit; debit rolled back
    }

    public function test_overdraft_allowed_within_credit_limit(): void
    {
        $card = $this->cards->issue($this->patient(), ['accepts_credit' => true, 'max_credit' => '50.00']);

        $this->cards->debit($card, '40.00', 'On credit');
        $this->assertSame('-40.00', (string) $card->fresh()->balance);

        $this->expectException(InsufficientFundsException::class);
        $this->cards->debit($card, '20.00', 'Over the limit'); // -60 exceeds -50
    }

    public function test_suspended_card_cannot_transact(): void
    {
        $card = $this->cards->issue($this->patient(), []);
        $card->update(['status' => CardStatus::Suspended]);

        $this->expectException(\RuntimeException::class);
        $this->cards->credit($card, '10.00', null);
    }
}
