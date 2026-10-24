<?php

namespace App\Livewire\Patients\Tabs;

use App\Models\Patient;
use App\Services\GrowthService;
use App\Support\MotherChild\MotherChild;
use App\Support\MotherChild\WhoGrowth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A child's growth chart (roadmap 04 §4): weight-for-age against the WHO
 * standards, the length/height and MUAC bands, the faltering flag, and the
 * measurements behind them (the clinic's and the visits' vitals). Thin: the
 * rules are in GrowthService; the chart geometry is drawn here.
 *
 * @property-read Patient $patient
 * @property-read list<array<string,mixed>> $points
 * @property-read array<string,mixed> $assessment
 */
#[Lazy]
class Growth extends Component
{
    use AuthorizesRequests;

    public const W = 640;

    public const H = 240;

    public const PAD = 30;

    #[Locked]
    public int $patientId;

    public bool $showForm = false;

    public ?string $measuredOn = null;

    public ?string $weight = null;

    public ?string $height = null;

    public ?string $muac = null;

    public bool $oedema = false;

    public string $notes = '';

    public function mount(int $patientId): void
    {
        $this->patientId = $patientId;
        $this->authorize('view', $this->patient);
        abort_unless(MotherChild::canView(auth()->user()), 403);
    }

    #[Computed]
    public function patient(): Patient
    {
        return Patient::query()->findOrFail($this->patientId);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function points(): array
    {
        return app(GrowthService::class)->points($this->patient);
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function assessment(): array
    {
        return app(GrowthService::class)->assessment($this->patient, $this->points);
    }

    /**
     * The weight-for-age chart as SVG coordinates: the WHO lines at -3, -2, 0
     * and +2 and the child's own weighings. Null when there is nothing to draw.
     *
     * @return array<string,mixed>|null
     */
    public function chart(): ?array
    {
        // The lines and the axes are GrowthService's, so the app draws the same chart.
        $ref = app(GrowthService::class)->weightReference($this->patient, $this->points);
        if ($ref === null) {
            return null;
        }
        $weighed = GrowthService::chartable($this->points);
        $xMax = $ref['months'];
        $yMax = $ref['kg_max'];

        $x = fn (float $months) => round(self::PAD + ($months / $xMax) * (self::W - self::PAD - 8), 1);
        $y = fn (float $kg) => round(self::H - self::PAD - ($kg / $yMax) * (self::H - self::PAD - 8), 1);

        $classes = [-3 => 'mc-ref-3', -2 => 'mc-ref-2', 0 => 'mc-ref-0', 2 => 'mc-ref-2'];
        $lines = [];
        foreach ($ref['lines'] as $line) {
            $z = $line['z'];
            $last = end($line['points']);
            $lines[] = [
                'class' => $classes[$z] ?? 'mc-ref-2',
                'points' => implode(' ', array_map(fn (array $pt) => $x($pt[0]).','.$y($pt[1]), $line['points'])),
                'label' => ($z > 0 ? '+' : '').$z,
                'lx' => $x($xMax) + 2,
                'ly' => $y($last === false ? 0.0 : $last[1]),
            ];
        }

        $dots = array_map(fn ($p) => [
            'cx' => $x($p['age_days'] / WhoGrowth::DAYS_PER_MONTH), 'cy' => $y((float) $p['weight']),
            'title' => Carbon::parse($p['date'])->format('d M Y').': '.$p['weight'].' kg at '.$p['age_label'],
            'tone' => $p['weight_band']['tone'] ?? 'neutral',
        ], $weighed);

        $xTicks = [];
        for ($m = 0; $m <= $xMax; $m += $xMax > 24 ? 12 : 3) {
            $xTicks[] = ['x' => $x($m), 'label' => $m.' m'];
        }
        $yTicks = [];
        $step = $yMax > 20 ? 5 : 2;
        for ($kg = 0; $kg <= $yMax; $kg += $step) {
            $yTicks[] = ['y' => $y($kg), 'label' => $kg.' kg'];
        }

        return [
            'w' => self::W, 'h' => self::H, 'pad' => self::PAD, 'lines' => $lines, 'dots' => $dots,
            'path' => implode(' ', array_map(fn ($d) => $d['cx'].','.$d['cy'], $dots)),
            'xTicks' => $xTicks, 'yTicks' => $yTicks, 'base' => self::H - self::PAD,
        ];
    }

    public function openForm(): void
    {
        abort_unless(MotherChild::canRecord(auth()->user()), 403);
        $this->resetErrorBag();
        $this->reset(['weight', 'height', 'muac', 'oedema', 'notes']);
        $this->measuredOn = Carbon::today()->toDateString();
        $this->showForm = true;
    }

    public function save(GrowthService $service): void
    {
        abort_unless(MotherChild::canRecord(auth()->user()), 403);

        try {
            $service->record($this->patient, [
                'measured_on' => $this->measuredOn, 'weight' => $this->weight, 'height' => $this->height,
                'muac' => $this->muac, 'oedema' => $this->oedema, 'notes' => $this->notes,
            ], null, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError(['measured_on' => 'measuredOn'][$key] ?? $key, $messages[0]);
            }

            return;
        }

        $this->showForm = false;
        unset($this->points, $this->assessment);
        $this->dispatch('toast', message: 'Measurement recorded.', type: 'success');
    }

    public function placeholder()
    {
        return view('livewire.partials.skeleton');
    }

    public function render()
    {
        $this->authorize('view', $this->patient);

        return view('livewire.patients.tabs.growth', [
            'canRecord' => MotherChild::canRecord(auth()->user()),
            'chart' => $this->chart(),
        ]);
    }
}
