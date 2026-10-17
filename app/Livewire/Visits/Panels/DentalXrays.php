<?php

namespace App\Livewire\Visits\Panels;

use App\Models\Service;
use App\Services\DentalCaseSheetService;
use App\Support\CaseSheet\DentalCaseSheet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Investigations on the dental case sheet: an X-ray taken in the chair
 * (kind, teeth, one-line finding, the image if there is one), recorded
 * without a radiology order and billed from the price list; and the lab tests
 * on the visit with their results.
 *
 * @property-read list<array<string,mixed>> $xrays
 * @property-read list<array<string,mixed>> $labs
 * @property-read Collection<int, Service> $services
 */
class DentalXrays extends Component
{
    use AuthorizesRequests, InteractsWithVisit, WithFileUploads;

    public bool $adding = false;

    public string $kind = 'periapical';

    public string $teeth = '';

    public string $finding = '';

    public ?int $serviceId = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $image = null;

    public function mount(int $visitId): void
    {
        $this->visitId = $visitId;
        $this->authorize('view', $this->visit);
        abort_unless(DentalCaseSheetService::canRead(auth()->user()), 403);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function xrays(): array
    {
        return app(DentalCaseSheetService::class)->xrays($this->visit);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function labs(): array
    {
        return app(DentalCaseSheetService::class)->labs($this->visit);
    }

    /** @return Collection<int, Service> */
    #[Computed]
    public function services(): Collection
    {
        return app(DentalCaseSheetService::class)->xrayServices();
    }

    #[On('dental-tooth-picked')]
    public function toothPicked(int $tooth): void
    {
        if ($this->adding && $this->teeth === '') {
            $this->teeth = (string) $tooth;
        }
    }

    public function open(): void
    {
        $this->authorize('diagnose', $this->visit);
        $this->reset(['kind', 'teeth', 'finding', 'image']);
        $this->serviceId = app(DentalCaseSheetService::class)->serviceFor($this->kind)?->id;
        $this->resetErrorBag();
        $this->adding = true;
    }

    public function updatedKind(): void
    {
        $this->serviceId = app(DentalCaseSheetService::class)->serviceFor($this->kind)?->id;
    }

    public function save(DentalCaseSheetService $sheets): void
    {
        $this->authorize('diagnose', $this->visit);
        $this->validate([
            'image' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
        ], ['image.mimes' => 'An image (JPG, PNG or WebP) or a PDF.', 'image.max' => 'Up to 10 MB.']);

        try {
            $sheets->recordXray(
                $this->visit, $this->kind, preg_split('/[\s,;]+/', trim($this->teeth)) ?: [], $this->finding,
                $this->serviceId ? Service::query()->find($this->serviceId) : null, $this->image, auth()->user(),
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError(in_array($key, ['kind', 'teeth', 'finding'], true) ? $key : 'serviceId', $messages[0]);
            }

            return;
        } catch (\RuntimeException $e) {
            $this->addError('serviceId', $e->getMessage());

            return;
        }

        $billed = $this->serviceId !== null;
        $this->adding = false;
        $this->reset(['kind', 'teeth', 'finding', 'image', 'serviceId']);
        unset($this->xrays);
        $this->dispatch('toast', message: $billed ? 'X-ray recorded and billed on the visit.' : 'X-ray recorded.', type: 'success');
        $this->dispatch('visit-updated');
    }

    public function render()
    {
        return view('livewire.visits.panels.dental-xrays', [
            'kinds' => DentalCaseSheet::XRAY_KINDS,
            'canWrite' => auth()->user()->can('diagnose', $this->visit) && $this->visit->isOpen(),
        ]);
    }
}
