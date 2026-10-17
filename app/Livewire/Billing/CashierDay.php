<?php

namespace App\Livewire\Billing;

use App\Models\Invoice;
use App\Services\CashierDaySummary;
use App\Support\HospitalSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The cashier's day: what came in, by method and by person, to close the
 * till against. The same figures as the app's day summary and its PDF
 * (CashierDaySummary), so the drawer, the phone and the printout agree.
 *
 * @property-read array<string,mixed> $summary
 */
#[Layout('layouts.admin')]
class CashierDay extends Component
{
    use AuthorizesRequests;

    #[Url(history: true)]
    public string $date = '';

    public function mount(CashierDaySummary $summary): void
    {
        $this->authorize('viewAny', Invoice::class);

        if (! $this->validDate($this->date)) {
            $this->date = $summary->today(app(HospitalSettings::class)->hospital());
        }
    }

    public function updatedDate(): void
    {
        if (! $this->validDate($this->date)) {
            $this->date = app(CashierDaySummary::class)->today(app(HospitalSettings::class)->hospital());
        }
        unset($this->summary);
    }

    /** Back or forward a day; never past today. */
    public function shift(int $days): void
    {
        $today = app(CashierDaySummary::class)->today(app(HospitalSettings::class)->hospital());
        $next = Carbon::parse($this->date)->addDays($days)->toDateString();
        $this->date = $next > $today ? $today : $next;
        unset($this->summary);
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function summary(): array
    {
        return app(CashierDaySummary::class)->forDate(app(HospitalSettings::class)->hospital(), $this->date);
    }

    private function validDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        $parsed = Carbon::createFromFormat('Y-m-d', $date);

        return $parsed !== null && $parsed->toDateString() === $date
            && $date <= app(CashierDaySummary::class)->today(app(HospitalSettings::class)->hospital());
    }

    public function render(): View
    {
        $today = app(CashierDaySummary::class)->today(app(HospitalSettings::class)->hospital());

        return view('livewire.billing.cashier-day', [
            's' => $this->summary,
            'isToday' => $this->date === $today,
            'today' => $today,
        ])->title("Cashier's day");
    }
}
