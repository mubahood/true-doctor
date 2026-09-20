<?php

namespace App\Livewire\Onboarding\Steps;

use App\Http\Requests\ServiceRequest;
use App\Models\Service;
use App\Support\HospitalSettings;
use App\Support\SampleCatalogue;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Setup step: the price list. Nothing can be invoiced until at least one
 * service is priced and active, so this step offers both a single quick entry
 * and the starter catalogue — grouped by specialty, since a flat "general
 * clinic" list undersells what a real hospital charges for, from a dental
 * filling to a Caesarean section — with editable prices.
 *
 * Editing/removing an already-added service goes through ServicePolicy, same
 * as the full Price List page (AuthorizesRequests), not the looser
 * `authorizeSetup()` the quick-add actions use below: those two paths are
 * already proven for hospital_admin (RbacSeeder grants `services.manage`), and
 * a precise mutation on an existing record deserves the tighter, standard gate.
 */
class Services extends Component
{
    use AuthorizesRequests, InteractsWithSetup;

    public string $name = '';

    public string $price = '';

    /** Starter rows the admin can price before importing: [name => price]. */
    public array $starterPrices = [];

    /** Starter rows the admin dismissed — kept out of the preview and the batch add. */
    public array $excluded = [];

    // ── Edit-in-popup for an already-added service ──────────────────────
    public bool $showEdit = false;

    #[Locked]
    public ?int $editingId = null;

    public string $editName = '';

    public string $editPrice = '';

    public function mount(): void
    {
        $this->authorizeSetup();

        foreach ($this->suggestions() as $row) {
            $this->starterPrices[$row['name']] = (string) ($row['price'] ?? 0);
        }
    }

    public function rules(): array
    {
        return array_intersect_key(ServiceRequest::rulesFor(), array_flip(['name', 'price']));
    }

    /** @return \Illuminate\Support\Collection<int, Service> */
    #[Computed]
    public function existing()
    {
        return Service::query()->where('is_active', true)->orderBy('name')->take(40)->get(['id', 'name', 'price']);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function suggestions(): array
    {
        $have = Service::query()->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all();

        return collect(SampleCatalogue::services())
            ->reject(fn (array $row) => in_array(mb_strtolower($row['name']), $have, true))
            ->reject(fn (array $row) => in_array($row['name'], $this->excluded, true))
            ->values()
            ->all();
    }

    /** Suggestions grouped by specialty, in the catalogue's own category order. */
    public function groupedSuggestions(): \Illuminate\Support\Collection
    {
        return collect($this->suggestions())->groupBy('category');
    }

    /** Drop one suggestion from the preview — it is not added, and won't reappear. */
    public function removeSuggestion(string $name): void
    {
        $this->excluded[] = $name;
        unset($this->starterPrices[$name]);
        unset($this->suggestions);
    }

    public function currency(): string
    {
        return app(HospitalSettings::class)->get('currency_symbol') ?: app(HospitalSettings::class)->currencyCode();
    }

    public function add(): void
    {
        $this->authorizeSetup();
        $data = $this->validate();

        Service::create(['name' => $data['name'], 'price' => $data['price'], 'is_active' => true, 'tax_exempt' => false]);

        $this->reset(['name', 'price']);
        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Service added to the price list.');
    }

    /** Import the starter list at the prices the admin just reviewed. */
    public function addStarter(): void
    {
        $this->authorizeSetup();

        $created = 0;
        foreach ($this->suggestions() as $row) {
            $price = $this->starterPrices[$row['name']] ?? $row['price'] ?? 0;
            if (! is_numeric($price) || (float) $price < 0) {
                $this->addError('starterPrices.'.$row['name'], 'Enter a valid price.');

                return;
            }

            Service::firstOrCreate(
                ['name' => $row['name']],
                ['price' => (string) $price, 'is_active' => true, 'tax_exempt' => (bool) ($row['tax_exempt'] ?? false)],
            );
            $created++;
        }

        unset($this->existing, $this->suggestions);
        $this->stepCompleted($created > 0 ? $created.' services added.' : 'Every starter service is already on your price list.');
    }

    /** Open the edit popup for one already-added service. */
    public function edit(int $id): void
    {
        $service = Service::findOrFail($id);
        $this->authorize('update', $service);

        $this->editingId = $service->id;
        $this->editName = $service->name;
        $this->editPrice = (string) $service->price;
        $this->resetErrorBag();
        $this->showEdit = true;
    }

    public function saveEdit(): void
    {
        $service = Service::findOrFail($this->editingId);
        $this->authorize('update', $service);

        $data = $this->validate([
            'editName' => ServiceRequest::rulesFor($service->id)['name'],
            'editPrice' => ServiceRequest::rulesFor($service->id)['price'],
        ]);

        $service->update(['name' => $data['editName'], 'price' => $data['editPrice']]);

        $this->showEdit = false;
        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Service updated.');
    }

    public function delete(int $id): void
    {
        $service = Service::findOrFail($id);
        $this->authorize('delete', $service);

        $service->delete();

        unset($this->existing, $this->suggestions);
        $this->stepCompleted('Service removed.');
    }

    public function render()
    {
        $this->authorizeSetup();

        return view('livewire.onboarding.steps.services', ['currency' => $this->currency()]);
    }
}
