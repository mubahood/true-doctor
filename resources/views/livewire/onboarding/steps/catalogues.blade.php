<div>
  {{-- ── Lab tests ─────────────────────────────────────────────────────── --}}
  <span class="tb-label">Lab tests</span>

  @if($this->existingLabTests->isNotEmpty())
    <div class="tb-table-wrap">
      <table class="tb-table">
        <thead><tr><th>Test</th><th class="tb-text-right">Price</th><th class="tb-text-right"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          @foreach($this->existingLabTests as $test)
            <tr wire:key="wz-have-lt-{{ $test->id }}">
              <td class="tb-fw-500">{{ $test->name }}</td>
              <td class="tb-text-right"><x-ui.money :amount="$test->price" /></td>
              <td class="tb-text-right">
                <x-ui.icon-button label="Edit {{ $test->name }}" icon="fa-pen" wire:click="editLabTest({{ $test->id }})" />
                <x-ui.icon-button label="Remove {{ $test->name }}" icon="fa-trash" variant="danger"
                                   wire:click="deleteLabTest({{ $test->id }})" wire:confirm="Remove {{ $test->name }} from the catalogue?" />
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  @if($this->labTestSuggestions !== [])
    <div class="tb-mt-4">
      <p class="muted tb-small tb-mb-4">Common tests for a general lab. Adjust any price, drop any you don't run, then add the rest.</p>
      <div class="tb-table-wrap">
        <table class="tb-table">
          <thead><tr><th>Test</th><th class="tb-text-right">Price ({{ $currency }})</th><th class="tb-text-right"><span class="sr-only">Remove</span></th></tr></thead>
          <tbody>
            @foreach($this->labTestSuggestions as $row)
              <tr wire:key="wz-lt-{{ Str::slug($row['name']) }}">
                <td class="tb-fw-500">{{ $row['name'] }}</td>
                <td class="tb-text-right">
                  <label class="sr-only" for="wz-ltprice-{{ Str::slug($row['name']) }}">Price for {{ $row['name'] }}</label>
                  <input id="wz-ltprice-{{ Str::slug($row['name']) }}" type="number" step="0.01" min="0"
                         wire:model="starterLabTestPrices.{{ $row['name'] }}" class="tb-input smpl-input">
                  @error('starterLabTestPrices.'.$row['name'])<div class="tb-field-error">{{ $message }}</div>@enderror
                </td>
                <td class="tb-text-right">
                  <x-ui.icon-button label="Don't add {{ $row['name'] }}" icon="fa-xmark"
                                     wire:click="removeLabTestSuggestion('{{ $row['name'] }}')" />
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="wz-actions">
        <button type="button" wire:click="addStarterLabTests" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="addStarterLabTests">
          <span wire:loading.remove wire:target="addStarterLabTests"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Add these {{ count($this->labTestSuggestions) }} tests</span>
          <span wire:loading wire:target="addStarterLabTests"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Adding…</span>
        </button>
      </div>
    </div>
  @endif

  <form wire:submit="addLabTest" class="tb-mt-4">
    <div class="tb-form-grid">
      <x-ui.field label="Test name" for="wz-lt" name="labTestName" required>
        <input id="wz-lt" type="text" wire:model="labTestName" class="tb-input" placeholder="e.g. Blood grouping" required>
      </x-ui.field>
      <x-ui.field label="Price ({{ $currency }})" for="wz-ltp" name="labTestPrice" required>
        <input id="wz-ltp" type="number" step="0.01" min="0" wire:model="labTestPrice" class="tb-input" required>
      </x-ui.field>
    </div>
    <div class="wz-actions">
      <button type="submit" class="btn-tb btn-tb-ghost" wire:loading.attr="disabled" wire:target="addLabTest">
        <i class="fas fa-plus" aria-hidden="true"></i> Add this test
      </button>
    </div>
  </form>

  <div class="wz-divider" role="separator"></div>

  {{-- ── Stock categories ──────────────────────────────────────────────── --}}
  <span class="tb-label">Stock categories</span>

  @if($this->existingCategories->isNotEmpty())
    <div class="tb-table-wrap">
      <table class="tb-table">
        <thead><tr><th>Category</th><th>Unit</th><th class="tb-text-right"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          @foreach($this->existingCategories as $category)
            <tr wire:key="wz-have-cat-{{ $category->id }}">
              <td class="tb-fw-500">{{ $category->name }}</td>
              <td class="muted">{{ $category->unit }}</td>
              <td class="tb-text-right">
                <x-ui.icon-button label="Edit {{ $category->name }}" icon="fa-pen" wire:click="editCategory({{ $category->id }})" />
                <x-ui.icon-button label="Remove {{ $category->name }}" icon="fa-trash" variant="danger"
                                   wire:click="deleteCategory({{ $category->id }})" wire:confirm="Remove {{ $category->name }}?" />
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  @if($this->categorySuggestions !== [])
    <div class="tb-mt-4">
      <p class="muted tb-small tb-mb-4">The categories most pharmacies group stock into. Drop any you don't need, then add the rest.</p>
      <div class="tb-table-wrap">
        <table class="tb-table">
          <thead><tr><th>Category</th><th>Unit</th><th class="tb-text-right"><span class="sr-only">Remove</span></th></tr></thead>
          <tbody>
            @foreach($this->categorySuggestions as $row)
              <tr wire:key="wz-cat-{{ Str::slug($row['name']) }}">
                <td class="tb-fw-500">{{ $row['name'] }}</td>
                <td class="muted">{{ $row['unit'] }}</td>
                <td class="tb-text-right">
                  <x-ui.icon-button label="Don't add {{ $row['name'] }}" icon="fa-xmark"
                                     wire:click="removeCategorySuggestion('{{ $row['name'] }}')" />
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="wz-actions">
        <button type="button" wire:click="addStarterCategories" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="addStarterCategories">
          <span wire:loading.remove wire:target="addStarterCategories"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Add these {{ count($this->categorySuggestions) }} categories</span>
          <span wire:loading wire:target="addStarterCategories"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Adding…</span>
        </button>
      </div>
    </div>
  @endif

  <form wire:submit="addCategory" class="tb-mt-4">
    <div class="tb-form-grid">
      <x-ui.field label="Category name" for="wz-cat" name="categoryName" required>
        <input id="wz-cat" type="text" wire:model="categoryName" class="tb-input" placeholder="e.g. Vaccines" required>
      </x-ui.field>
      <x-ui.field label="Unit" for="wz-catunit" name="categoryUnit" hint="e.g. vial, tablet, bottle." required>
        <input id="wz-catunit" type="text" wire:model="categoryUnit" class="tb-input" maxlength="32" required>
      </x-ui.field>
    </div>
    <div class="wz-actions">
      <button type="submit" class="btn-tb btn-tb-ghost" wire:loading.attr="disabled" wire:target="addCategory">
        <i class="fas fa-plus" aria-hidden="true"></i> Add this category
      </button>
    </div>
  </form>

  <x-ui.modal show="showEditLabTest" title="Edit test" size="sm">
    @if($showEditLabTest)
      <form wire:submit="saveLabTestEdit">
        <div class="tb-modal-body">
          <x-ui.field label="Test name" for="wz-editlt" name="editLabTestName" required>
            <input id="wz-editlt" type="text" wire:model="editLabTestName" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Price ({{ $currency }})" for="wz-editltp" name="editLabTestPrice" required>
            <input id="wz-editltp" type="number" step="0.01" min="0" wire:model="editLabTestPrice" class="tb-input" required>
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveLabTestEdit">
            <span wire:loading.remove wire:target="saveLabTestEdit">Save changes</span>
            <span wire:loading wire:target="saveLabTestEdit"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>

  <x-ui.modal show="showEditCategory" title="Edit category" size="sm">
    @if($showEditCategory)
      <form wire:submit="saveCategoryEdit">
        <div class="tb-modal-body">
          <x-ui.field label="Category name" for="wz-editcat" name="editCategoryName" required>
            <input id="wz-editcat" type="text" wire:model="editCategoryName" class="tb-input" required>
          </x-ui.field>
          <x-ui.field label="Unit" for="wz-editcatunit" name="editCategoryUnit" hint="e.g. vial, tablet, bottle." required>
            <input id="wz-editcatunit" type="text" wire:model="editCategoryUnit" class="tb-input" maxlength="32" required>
          </x-ui.field>
        </div>
        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" @click="requestClose()">Cancel</button>
          <button type="submit" class="btn-tb btn-tb-primary" wire:loading.attr="disabled" wire:target="saveCategoryEdit">
            <span wire:loading.remove wire:target="saveCategoryEdit">Save changes</span>
            <span wire:loading wire:target="saveCategoryEdit"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </form>
    @endif
  </x-ui.modal>
</div>
