<?php

namespace App\Livewire\Reports;

use App\Services\ReportService;
use App\Support\CurrentHospital;
use App\Support\ReportRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reports & dashboards (Board shape) — revenue, stock valuation, occupancy,
 * demographics, doctor productivity and service revenue for a date range.
 *
 * The range lives in the URL (#[Url]) so a report is shareable and survives
 * back/forward; changing a date is a live Livewire round-trip, not a GET
 * reload (plan D7). Every section is a #[Computed] wrapped in a 60 s cache
 * keyed by hospital + range (plan L2), so re-rendering on a keystroke, a poll
 * or a back-navigation costs nothing.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    /** Seconds a report section stays cached. */
    private const TTL = 60;

    #[Url(history: true)]
    public string $from = '';

    #[Url(history: true)]
    public string $to = '';

    public function mount(): void
    {
        $this->authorizeView();
        $this->normaliseRange();
    }

    private function authorizeView(): void
    {
        abort_unless(Auth::user()?->can('reports.view'), 403);
    }

    /** Empty or unreadable falls back to this month (ReportRange), mid-edit ranges left alone. */
    private function normaliseRange(): void
    {
        [$this->from, $this->to] = ReportRange::read($this->from, $this->to, swap: false);
    }

    public function updatedFrom(): void
    {
        $this->normaliseRange();
    }

    public function updatedTo(): void
    {
        $this->normaliseRange();
    }

    public function fromDate(): Carbon
    {
        return Carbon::parse($this->from);
    }

    public function toDate(): Carbon
    {
        return Carbon::parse($this->to);
    }

    /** Cache key for one section of one range within one tenant. */
    private function remember(string $section, \Closure $fn): mixed
    {
        $hospital = app(CurrentHospital::class)->id() ?? 'none';

        return Cache::remember("reports:{$hospital}:{$section}:{$this->from}:{$this->to}", self::TTL, $fn);
    }

    #[Computed]
    public function revenue(): array
    {
        return $this->remember('revenue', fn () => app(ReportService::class)->revenue($this->fromDate(), $this->toDate()));
    }

    #[Computed]
    public function valuation(): array
    {
        return $this->remember('valuation', fn () => app(ReportService::class)->stockValuation());
    }

    #[Computed]
    public function occupancy(): array
    {
        return $this->remember('occupancy', fn () => app(ReportService::class)->occupancy());
    }

    #[Computed]
    public function demographics(): array
    {
        return $this->remember('demographics', fn () => app(ReportService::class)->demographics());
    }

    #[Computed]
    public function productivity(): array
    {
        return $this->remember('productivity', fn () => app(ReportService::class)->doctorProductivity($this->fromDate(), $this->toDate()));
    }

    #[Computed]
    public function serviceRevenue(): array
    {
        return $this->remember('service-revenue', fn () => app(ReportService::class)->serviceRevenue($this->fromDate(), $this->toDate()));
    }

    #[Computed]
    public function inpatient(): array
    {
        return $this->remember('inpatient', fn () => app(ReportService::class)->inpatientNights($this->fromDate(), $this->toDate()));
    }

    #[Computed]
    public function outstanding(): array
    {
        return $this->remember('outstanding', fn () => app(ReportService::class)->outstanding());
    }

    /**
     * The ranges somebody actually asks for.
     *
     * Two date pickers are a way to express any range and a slow way to
     * express the five that get asked for daily. The pickers stay — this is
     * beside them, not instead.
     *
     * @return array<string,string> label => key
     */
    public function presets(): array
    {
        return ReportRange::presets();
    }

    /** @return array{0:string,1:string} */
    public function rangeFor(string $key): array
    {
        return ReportRange::forPreset($key);
    }

    public function usePreset(string $key): void
    {
        [$this->from, $this->to] = ReportRange::forPreset($key);
    }

    /** Which preset the current range happens to be, so one can look chosen. */
    public function activePreset(): ?string
    {
        return ReportRange::presetOf($this->from, $this->to);
    }

    public function render()
    {
        $this->authorizeView();

        return view('livewire.reports.index')->title('Reports');
    }
}
