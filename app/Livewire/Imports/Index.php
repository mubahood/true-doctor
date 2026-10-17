<?php

namespace App\Livewire\Imports;

use App\Enums\ImportKind;
use App\Enums\ImportStatus;
use App\Livewire\Concerns\WithTable;
use App\Models\ImportBatch;
use App\Services\DataImportService;
use App\Support\CurrentHospital;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings, Import data: download a template, upload a sheet, and the log of
 * every import with who did it (docs/imports.md). The work itself is in
 * DataImportService; one import is worked through on Imports\Show.
 */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithFileUploads, WithTable;

    public string $kind = 'patients';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $file = null;

    public function mount(): void
    {
        $this->allowed();
    }

    private function allowed(): void
    {
        abort_unless(Auth::user()?->can('manage-settings'), 403);
        // One clinic's records: with no hospital resolved there is nowhere to import to.
        abort_if(app(CurrentHospital::class)->id() === null, 403);
    }

    private function kindOrFail(string $value, DataImportService $imports): ImportKind
    {
        $kind = ImportKind::tryFrom($value);
        abort_if($kind === null || ! in_array($kind, $imports->kinds(), true), 404);

        return $kind;
    }

    /** The .xlsx template for a kind: the columns, an example row, a sheet of notes. */
    public function template(string $kind, DataImportService $imports): StreamedResponse
    {
        $this->allowed();
        $kind = $this->kindOrFail($kind, $imports);
        $bytes = $imports->template($kind);

        return response()->streamDownload(function () use ($bytes) {
            echo $bytes;
        }, \Illuminate\Support\Str::slug($kind->label()).'-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function upload(DataImportService $imports): mixed
    {
        $this->allowed();
        $kind = $this->kindOrFail($this->kind, $imports);

        $this->validate(['file' => ['required', 'file', 'max:10240']], [
            'file.required' => 'Choose the file to import.',
            'file.max' => 'The file is larger than 10 MB. Split it into smaller sheets.',
        ]);

        $name = (string) $this->file->getClientOriginalName();
        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['xlsx', 'csv', 'txt', 'xls'], true)) {
            $this->addError('file', 'Upload an Excel workbook (.xlsx) or a CSV file.');

            return null;
        }

        try {
            $batch = $imports->start($kind, (string) $this->file->getRealPath(), $name, Auth::id());
        } catch (\RuntimeException $e) {
            $this->addError('file', $e->getMessage());

            return null;
        } finally {
            $this->file = null;
        }

        return $this->redirectRoute('admin.imports.show', $batch, navigate: true);
    }

    public function render(DataImportService $imports): View
    {
        $rows = ImportBatch::query()
            ->with(['creator:id,name', 'undoer:id,name'])
            ->where('status', '!=', ImportStatus::Cancelled->value)
            ->when($this->search !== '', fn ($q) => $q->where('file_name', 'like', '%'.$this->search.'%'))
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.imports.index', [
            'kinds' => $imports->kinds(),
            'rows' => $rows,
            'filtered' => $this->search !== '',
        ])->title('Import data');
    }
}
