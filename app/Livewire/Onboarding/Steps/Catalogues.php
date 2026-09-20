<?php

namespace App\Livewire\Onboarding\Steps;

use App\Http\Requests\LabTestRequest;
use App\Http\Requests\StockCategoryRequest;
use App\Models\LabTest;
use App\Models\StockCategory;
use App\Support\HospitalSettings;
use App\Support\SampleCatalogue;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Setup step: the lab test and stock category catalogues (recommended — this
 * step is done once EITHER has one entry, since a hospital may run only a lab,
 * only a pharmacy, or both). Two independent lists side by side, each with its
 * own quick entry and starter set, so a step that touches two unrelated models
 * still reads as one simple screen rather than two separate ones.
 *
 * Editing/removing an already-added row goes through LabTestPolicy /
 * StockCategoryPolicy (AuthorizesRequests), same as the full pages — not the
 * looser `authorizeSetup()` the quick-add actions use below.
 */
class Catalogues extends Component
{
    use AuthorizesRequests, InteractsWithSetup;

    // ── Lab tests ────────────────────────────────────────────────────────
    public string $labTestName = '';

    public string $labTestPrice = '';

    public array $excludedLabTests = [];

    /** Editable starter prices, keyed by test name. */
    public array $starterLabTestPrices = [];

    public bool $showEditLabTest = false;

    #[Locked]
    public ?int $editingLabTestId = null;

    public string $editLabTestName = '';

    public string $editLabTestPrice = '';

    // ── Stock categories ─────────────────────────────────────────────────
    public string $categoryName = '';

    public string $categoryUnit = '';

    public array $excludedCategories = [];

    public bool $showEditCategory = false;

    #[Locked]
    public ?int $editingCategoryId = null;

    public string $editCategoryName = '';

    public string $editCategoryUnit = '';

    public function mount(): void
    {
        $this->authorizeSetup();

        foreach ($this->labTestSuggestions() as $row) {
            $this->starterLabTestPrices[$row['name']] = (string) $row['price'];
        }
    }

    public function labTestRules(): array
    {
        return [
            'labTestName' => LabTestRequest::rulesFor()['name'],
            'labTestPrice' => LabTestRequest::rulesFor()['price'],
        ];
    }

    public function categoryRules(): array
    {
        return [
            'categoryName' => StockCategoryRequest::rulesFor()['name'],
            'categoryUnit' => StockCategoryRequest::rulesFor()['unit'],
        ];
    }

    public function currency(): string
    {
        return app(HospitalSettings::class)->get('currency_symbol') ?: app(HospitalSettings::class)->currencyCode();
    }

    /** @return \Illuminate\Support\Collection<int, LabTest> */
    #[Computed]
    public function existingLabTests()
    {
        return LabTest::query()->where('is_active', true)->orderBy('name')->take(20)->get(['id', 'name', 'price']);
    }

    /** @return \Illuminate\Support\Collection<int, StockCategory> */
    #[Computed]
    public function existingCategories()
    {
        return StockCategory::query()->orderBy('name')->take(20)->get(['id', 'name', 'unit']);
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function labTestSuggestions(): array
    {
        $have = LabTest::query()->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all();

        return collect(SampleCatalogue::labTests())
            ->reject(fn (array $row) => in_array(mb_strtolower($row['name']), $have, true))
            ->reject(fn (array $row) => in_array($row['name'], $this->excludedLabTests, true))
            ->take(6)
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    #[Computed]
    public function categorySuggestions(): array
    {
        $have = StockCategory::query()->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all();

        return collect(SampleCatalogue::stockCategories())
            ->reject(fn (array $row) => in_array(mb_strtolower($row['name']), $have, true))
            ->reject(fn (array $row) => in_array($row['name'], $this->excludedCategories, true))
            ->values()
            ->all();
    }

    public function removeLabTestSuggestion(string $name): void
    {
        $this->excludedLabTests[] = $name;
        unset($this->starterLabTestPrices[$name], $this->labTestSuggestions);
    }

    public function removeCategorySuggestion(string $name): void
    {
        $this->excludedCategories[] = $name;
        unset($this->categorySuggestions);
    }

    public function addLabTest(): void
    {
        $this->authorizeSetup();
        $data = $this->validate($this->labTestRules());

        LabTest::create(['name' => $data['labTestName'], 'price' => $data['labTestPrice'], 'is_active' => true]);

        $this->reset(['labTestName', 'labTestPrice']);
        unset($this->existingLabTests, $this->labTestSuggestions);
        $this->stepCompleted('Test added to the catalogue.');
    }

    public function addCategory(): void
    {
        $this->authorizeSetup();
        $data = $this->validate($this->categoryRules());

        StockCategory::create(['name' => $data['categoryName'], 'unit' => $data['categoryUnit'], 'is_active' => true]);

        $this->reset(['categoryName', 'categoryUnit']);
        unset($this->existingCategories, $this->categorySuggestions);
        $this->stepCompleted('Category added.');
    }

    /** One click: create every suggested lab test the hospital does not have yet. */
    public function addStarterLabTests(): void
    {
        $this->authorizeSetup();

        $created = 0;
        foreach ($this->labTestSuggestions() as $row) {
            $price = $this->starterLabTestPrices[$row['name']] ?? $row['price'];
            if (! is_numeric($price) || (float) $price < 0) {
                $this->addError('starterLabTestPrices.'.$row['name'], 'Enter a valid price.');

                return;
            }

            LabTest::firstOrCreate(
                ['name' => $row['name']],
                [
                    'specimen' => $row['specimen'] ?? null,
                    'unit' => $row['unit'] ?? null,
                    'reference_range' => $row['reference_range'] ?? null,
                    'price' => (string) $price,
                    'is_active' => true,
                ],
            );
            $created++;
        }

        unset($this->existingLabTests, $this->labTestSuggestions);
        $this->stepCompleted($created > 0 ? $created.' tests added to the catalogue.' : 'Every suggested test is already in your catalogue.');
    }

    /** One click: create every suggested stock category the hospital does not have yet. */
    public function addStarterCategories(): void
    {
        $this->authorizeSetup();

        $created = 0;
        foreach ($this->categorySuggestions() as $row) {
            StockCategory::firstOrCreate(['name' => $row['name']], ['unit' => $row['unit'], 'is_active' => true]);
            $created++;
        }

        unset($this->existingCategories, $this->categorySuggestions);
        $this->stepCompleted($created > 0 ? $created.' categories added.' : 'Every suggested category is already set up.');
    }

    // ── Edit / delete: lab tests ─────────────────────────────────────────

    public function editLabTest(int $id): void
    {
        $test = LabTest::findOrFail($id);
        $this->authorize('update', $test);

        $this->editingLabTestId = $test->id;
        $this->editLabTestName = $test->name;
        $this->editLabTestPrice = (string) $test->price;
        $this->resetErrorBag();
        $this->showEditLabTest = true;
    }

    public function saveLabTestEdit(): void
    {
        $test = LabTest::findOrFail($this->editingLabTestId);
        $this->authorize('update', $test);

        $rules = LabTestRequest::rulesFor($test->id);
        $data = $this->validate(['editLabTestName' => $rules['name'], 'editLabTestPrice' => $rules['price']]);

        $test->update(['name' => $data['editLabTestName'], 'price' => $data['editLabTestPrice']]);

        $this->showEditLabTest = false;
        unset($this->existingLabTests, $this->labTestSuggestions);
        $this->stepCompleted('Test updated.');
    }

    public function deleteLabTest(int $id): void
    {
        $test = LabTest::findOrFail($id);
        $this->authorize('delete', $test);

        $test->delete();

        unset($this->existingLabTests, $this->labTestSuggestions);
        $this->stepCompleted('Test removed.');
    }

    // ── Edit / delete: stock categories ──────────────────────────────────

    public function editCategory(int $id): void
    {
        $category = StockCategory::findOrFail($id);
        $this->authorize('update', $category);

        $this->editingCategoryId = $category->id;
        $this->editCategoryName = $category->name;
        $this->editCategoryUnit = $category->unit;
        $this->resetErrorBag();
        $this->showEditCategory = true;
    }

    public function saveCategoryEdit(): void
    {
        $category = StockCategory::findOrFail($this->editingCategoryId);
        $this->authorize('update', $category);

        $rules = StockCategoryRequest::rulesFor($category->id);
        $data = $this->validate(['editCategoryName' => $rules['name'], 'editCategoryUnit' => $rules['unit']]);

        $category->update(['name' => $data['editCategoryName'], 'unit' => $data['editCategoryUnit']]);

        $this->showEditCategory = false;
        unset($this->existingCategories, $this->categorySuggestions);
        $this->stepCompleted('Category updated.');
    }

    /** A category that still has stock items may not be removed — mirrors StockCategories\Index::assertDeletable(). */
    public function deleteCategory(int $id): void
    {
        $category = StockCategory::findOrFail($id);
        $this->authorize('delete', $category);

        if ($category->items()->exists()) {
            $this->dispatch('toast', message: 'This category has stock items — reassign them first, from the full Categories page.', type: 'error');

            return;
        }

        $category->delete();

        unset($this->existingCategories, $this->categorySuggestions);
        $this->stepCompleted('Category removed.');
    }

    public function render()
    {
        $this->authorizeSetup();

        return view('livewire.onboarding.steps.catalogues', ['currency' => $this->currency()]);
    }
}
