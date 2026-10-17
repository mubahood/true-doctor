<?php

namespace App\Services;

use App\Enums\ImportKind;
use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Services\Imports\AppointmentsImport;
use App\Services\Imports\ImportField;
use App\Services\Imports\ImportHandler;
use App\Services\Imports\PatientsImport;
use App\Services\Imports\ServicesImport;
use App\Services\Imports\StockImport;
use App\Support\CurrentHospital;
use App\Support\Imports\SpreadsheetReader;
use App\Support\Imports\XlsxWriter;
use App\Support\Practice\Practice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Settings, Import data (docs/imports.md): a clinic's spreadsheet brought in,
 * one kind of record at a time.
 *
 *   start()       read the sheet, keep every row as it was, guess the columns
 *   map()         the columns as the person confirmed them; checking begins
 *   step()        do the next chunk of whatever is running (check, import, undo)
 *   decide()      what to do with a row that looks like a registered record
 *   import()      save it: each row through the service that owns that record
 *   undo()        take back what the batch added, within a day, if untouched
 *
 * The long work runs a chunk per request, driven by the page, so a sheet of
 * 10,000 rows works on shared hosting with no shell, no queue worker and a
 * 30-second limit; a page closed halfway picks up where it stopped. Nothing
 * is saved until the person has seen the check and pressed Import.
 */
class DataImportService
{
    /** Rows per request, per kind of work. */
    public const CHECK_CHUNK = 200;

    public const IMPORT_CHUNK = 40;

    public const UNDO_CHUNK = 40;

    public function __construct(private readonly CurrentHospital $current) {}

    /** The kinds this practice can import. @return list<ImportKind> */
    public function kinds(): array
    {
        $practice = Practice::current();

        return array_values(array_filter(ImportKind::cases(), fn (ImportKind $k) => $k !== ImportKind::Stock || $practice->uses('pharmacy')));
    }

    public function handler(ImportKind $kind): ImportHandler
    {
        return app(match ($kind) {
            ImportKind::Patients => PatientsImport::class,
            ImportKind::Services => ServicesImport::class,
            ImportKind::Stock => StockImport::class,
            ImportKind::Appointments => AppointmentsImport::class,
        });
    }

    // ── The template ──────────────────────────────────────────────────

    /** An .xlsx with the columns, one example row, and a sheet of notes on each column. */
    public function template(ImportKind $kind): string
    {
        $fields = $this->handler($kind)->fields();
        $labels = collect($fields)->mapWithKeys(fn (ImportField $f) => [$f->key => $f->label]);

        // "Yes", or "Yes, or Last name" where any one of a group will do.
        $needed = [];
        foreach ($this->handler($kind)->requiredColumns() as $group) {
            foreach ($group as $key) {
                $others = array_values(array_diff($group, [$key]));
                $needed[$key] = $others === [] ? 'Yes' : 'Yes, or '.collect($others)->map(fn ($k) => $labels[$k])->implode(' or ');
            }
        }

        $notes = [['Column', 'Needed', 'What to write']];
        foreach ($fields as $f) {
            $notes[] = [$f->label, $needed[$f->key] ?? 'No', $f->note];
        }
        $notes[] = ['', '', ''];
        $notes[] = ['', '', 'Delete the example row before you import. Keep the headings in the first row. Columns may be in any order, and columns we do not know are left out.'];

        return XlsxWriter::make([
            ['name' => $kind->label(), 'rows' => [
                array_map(fn (ImportField $f) => $f->label, $fields),
                $this->handler($kind)->exampleRow(),
            ]],
            ['name' => 'Notes', 'rows' => $notes],
        ]);
    }

    // ── Starting ──────────────────────────────────────────────────────

    /**
     * Read the uploaded sheet and keep its rows. Nothing else is saved.
     *
     * @throws RuntimeException with a sentence for the person when the file cannot be read
     */
    public function start(ImportKind $kind, string $path, string $fileName, ?int $by): ImportBatch
    {
        $hospitalId = $this->current->id() ?? throw new RuntimeException('Open a hospital before importing.');
        if (! in_array($kind, $this->kinds(), true)) {
            throw new RuntimeException('This practice does not keep '.strtolower($kind->label()).'.');
        }

        $rows = SpreadsheetReader::read($path, $fileName);
        if ($rows === []) {
            throw new RuntimeException('The sheet is empty.');
        }

        $headingLine = (int) array_key_first($rows);
        $headings = array_map('strval', $rows[$headingLine]);
        unset($rows[$headingLine]);
        if ($rows === []) {
            throw new RuntimeException('The sheet has headings but no rows under them.');
        }

        return DB::transaction(function () use ($kind, $fileName, $by, $hospitalId, $headings, $rows) {
            $batch = ImportBatch::query()->create([
                'uuid' => (string) Str::uuid(),
                'kind' => $kind,
                'status' => ImportStatus::Mapping,
                'file_name' => mb_substr(basename($fileName), 0, 191),
                'headings' => $headings,
                'column_map' => $this->guess($kind, $headings),
                'total_rows' => count($rows),
                'created_by' => $by,
            ]);

            $now = now();
            foreach (array_chunk($rows, 500, true) as $chunk) {
                $insert = [];
                foreach ($chunk as $line => $cells) {
                    // A bulk insert does not fire the model's creating hook, so
                    // the tenant is written here, from the batch it belongs to.
                    $insert[] = [
                        'hospital_id' => $hospitalId,
                        'import_batch_id' => $batch->id,
                        'line' => (int) $line,
                        'cells' => json_encode($cells, JSON_UNESCAPED_UNICODE),
                        'status' => ImportRow::PENDING,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                ImportRow::query()->insert($insert);
            }

            return $batch;
        });
    }

    /**
     * Which column holds which field, from the headings: "Tel", "Phone No"
     * and "Contact" all mean the phone. An exact name first, then a heading
     * that contains one; a column is used once.
     *
     * @param  list<string>  $headings
     * @return array<string,int|null>
     */
    public function guess(ImportKind $kind, array $headings): array
    {
        $fields = $this->handler($kind)->fields();
        $normal = array_map(fn ($h) => ImportField::normalise((string) $h), $headings);
        $map = array_fill_keys(array_map(fn (ImportField $f) => $f->key, $fields), null);
        $used = [];

        foreach ($fields as $f) {
            foreach ($normal as $i => $h) {
                if ($h !== '' && ! isset($used[$i]) && in_array($h, $f->names(), true)) {
                    $map[$f->key] = $i;
                    $used[$i] = true;
                    break;
                }
            }
        }

        foreach ($fields as $f) {
            if ($map[$f->key] !== null) {
                continue;
            }
            foreach ($normal as $i => $h) {
                if ($h === '' || isset($used[$i])) {
                    continue;
                }
                foreach ($f->names() as $name) {
                    if (mb_strlen($name) >= 4 && str_contains($h, $name)) {
                        $map[$f->key] = $i;
                        $used[$i] = true;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * The columns as the person confirmed them. Checks every row again from
     * the start.
     *
     * @param  array<string,int|string|null>  $map  field => column index, or null for none
     */
    public function map(ImportBatch $batch, array $map): ImportBatch
    {
        if (! $batch->status->isDraft()) {
            throw new RuntimeException('This import has already been saved.');
        }

        $handler = $this->handler($batch->kind);
        $count = count($batch->headings ?? []);
        $clean = [];
        foreach ($handler->fields() as $f) {
            $index = $map[$f->key] ?? null;
            $clean[$f->key] = $index === null || $index === '' || ! is_numeric($index) || (int) $index < 0 || (int) $index >= $count ? null : (int) $index;
        }

        $byLabel = collect($handler->fields())->keyBy('key');
        foreach ($handler->requiredColumns() as $any) {
            if (collect($any)->every(fn ($key) => $clean[$key] === null)) {
                $names = collect($any)->map(fn ($k) => '"'.$byLabel[$k]->label.'"')->implode(' or ');
                throw new RuntimeException('Choose the column for '.$names.'.');
            }
        }

        $taken = array_filter($clean, fn ($i) => $i !== null);
        if (count($taken) !== count(array_unique($taken))) {
            throw new RuntimeException('One column is chosen for two fields. Each column can be used once.');
        }

        $this->exclusive($batch, fn () => DB::transaction(function () use ($batch, $clean, $handler) {
            $this->claim($batch, fn (ImportStatus $s) => $s->isDraft(), 'This import has already been saved.');
            $batch->update(['column_map' => $clean, 'status' => ImportStatus::Checking]);
            $batch->rows()->update(['status' => ImportRow::PENDING, 'problems' => null, 'match_id' => null, 'match_reason' => null, 'decision' => null, 'extra' => null]);
            $this->markRepeats($batch->refresh(), $handler);
        }));

        return $batch->refresh();
    }

    /** Back to matching the columns, before anything is saved. */
    public function remap(ImportBatch $batch): ImportBatch
    {
        $this->exclusive($batch, fn () => DB::transaction(function () use ($batch) {
            $this->claim($batch, fn (ImportStatus $s) => $s->isDraft(), 'This import has already been saved.');
            $batch->update(['status' => ImportStatus::Mapping]);
        }));

        return $batch->refresh();
    }

    /**
     * Run a change to the batch while nothing else works on it: the same
     * lock step() holds for a chunk, so pressing a button while a poll is
     * mid-chunk waits for the chunk instead of racing it.
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    private function exclusive(ImportBatch $batch, \Closure $work): mixed
    {
        try {
            return Cache::lock('import-batch:'.$batch->id, 120)->block(15, $work);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw new RuntimeException('The import is busy with a step. Try again in a moment.');
        }
    }

    /**
     * Lock the batch, read its status as it is now (not as a page last saw
     * it), and refuse when the move is not allowed from there: two tabs, or
     * a step running while somebody presses a button, must not both win.
     *
     * @param  \Closure(ImportStatus): bool  $allowed
     */
    private function claim(ImportBatch $batch, \Closure $allowed, string $refusal): void
    {
        $status = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->value('status');
        $batch->status = $status instanceof ImportStatus ? $status : ImportStatus::from((string) $status);
        if (! $allowed($batch->status)) {
            throw new RuntimeException($refusal);
        }
    }

    /** A row typed twice in the same sheet is flagged on its second appearance. */
    private function markRepeats(ImportBatch $batch, ImportHandler $handler): void
    {
        $seen = [];
        $batch->rows()->orderBy('line')->select(['id', 'line', 'cells'])->chunkById(1000, function ($rows) use ($batch, $handler, &$seen) {
            foreach ($rows as $row) {
                $key = $handler->sheetKey($this->values($batch, $row));
                if ($key === null) {
                    continue;
                }
                if (isset($seen[$key])) {
                    ImportRow::query()->whereKey($row->id)->update(['extra' => json_encode(['repeat_of' => $seen[$key]])]);
                } else {
                    $seen[$key] = $row->line;
                }
            }
        });
    }

    /** The row's cells as field => text, through the column map. @return array<string,string> */
    public function values(ImportBatch $batch, ImportRow $row): array
    {
        $out = [];
        foreach ($batch->column_map ?? [] as $key => $index) {
            $out[$key] = $index === null ? '' : trim((string) ($row->cells[$index] ?? ''));
        }

        return $out;
    }

    // ── Running ───────────────────────────────────────────────────────

    /**
     * Do the next chunk of whatever is running, and say whether there is
     * more. Two tabs on the same import take turns rather than racing.
     */
    public function step(ImportBatch $batch, ?int $by): ImportBatch
    {
        if (! $batch->status->isWorking()) {
            return $batch;
        }

        $lock = Cache::lock('import-batch:'.$batch->id, 120);
        if (! $lock->get()) {
            return $batch;
        }

        try {
            $batch->refresh();
            match ($batch->status) {
                ImportStatus::Checking => $this->checkChunk($batch),
                ImportStatus::Importing => $this->importChunk($batch, $by),
                ImportStatus::Undoing => $this->undoChunk($batch, $by),
                default => null,
            };
        } finally {
            $lock->release();
        }

        return $batch->refresh();
    }

    private function checkChunk(ImportBatch $batch): void
    {
        $handler = $this->handler($batch->kind);
        $rows = $batch->rows()->where('status', ImportRow::PENDING)->orderBy('line')->limit(self::CHECK_CHUNK)->get();

        foreach ($rows as $row) {
            $result = $handler->check($this->values($batch, $row));
            $problems = $result['problems'];
            $repeat = $row->extra['repeat_of'] ?? null;
            if ($repeat !== null) {
                $problems[] = 'The same as row '.$repeat.' of this sheet.';
            }

            $match = $problems === [] ? $result['match'] : null;
            $row->update([
                'status' => $problems !== [] ? ImportRow::PROBLEM : ($match !== null ? ImportRow::DUPLICATE : ImportRow::READY),
                'problems' => $problems === [] ? null : $problems,
                'match_id' => $match['id'] ?? null,
                'match_reason' => $match !== null ? mb_substr($match['reason'].': '.$match['label'], 0, 100) : null,
                'decision' => $match !== null ? 'skip' : null,
            ]);
        }

        if (! $batch->rows()->where('status', ImportRow::PENDING)->exists()) {
            $this->finish($batch, ImportStatus::Checking, ['status' => ImportStatus::Checked]);
        }
    }

    /** What to do with one row that looks like a registered record. */
    public function decide(ImportBatch $batch, int $rowId, string $decision): void
    {
        if (! in_array($decision, $this->handler($batch->kind)->decisions(), true)) {
            throw new RuntimeException('That is not a choice for this kind of import.');
        }

        $this->exclusive($batch, fn () => DB::transaction(function () use ($batch, $rowId, $decision) {
            $this->claim($batch, fn (ImportStatus $s) => $s === ImportStatus::Checked, 'Choices can be changed only after the check and before importing.');
            $batch->rows()->whereKey($rowId)->where('status', ImportRow::DUPLICATE)->update(['decision' => $decision]);
        }));
    }

    /** The same choice for every row that looks registered. */
    public function decideAll(ImportBatch $batch, string $decision): void
    {
        if (! in_array($decision, $this->handler($batch->kind)->decisions(), true)) {
            throw new RuntimeException('That is not a choice for this kind of import.');
        }

        $this->exclusive($batch, fn () => DB::transaction(function () use ($batch, $decision) {
            $this->claim($batch, fn (ImportStatus $s) => $s === ImportStatus::Checked, 'Choices can be changed only after the check and before importing.');
            $batch->rows()->where('status', ImportRow::DUPLICATE)->update(['decision' => $decision]);
        }));
    }

    /** Begin saving the checked rows. */
    public function import(ImportBatch $batch): ImportBatch
    {
        $this->exclusive($batch, fn () => DB::transaction(function () use ($batch) {
            $this->claim($batch, fn (ImportStatus $s) => $s === ImportStatus::Checked, 'Check the sheet before importing it.');
            if (! $batch->rows()->whereIn('status', [ImportRow::READY, ImportRow::DUPLICATE])->exists()) {
                throw new RuntimeException('There is nothing to import: every row has a problem.');
            }
            $batch->update(['status' => ImportStatus::Importing, 'imported_at' => now()]);
        }));

        return $batch;
    }

    private function importChunk(ImportBatch $batch, ?int $by): void
    {
        $handler = $this->handler($batch->kind);
        $rows = $batch->rows()->whereIn('status', [ImportRow::READY, ImportRow::DUPLICATE])->orderBy('line')->limit(self::IMPORT_CHUNK)->get();

        foreach ($rows as $row) {
            if ($row->status === ImportRow::DUPLICATE && ($row->decision ?? 'skip') === 'skip') {
                $row->update(['status' => ImportRow::SKIPPED, 'message' => 'Skipped: looks registered already.']);

                continue;
            }

            $decision = $row->status === ImportRow::DUPLICATE ? (string) $row->decision : 'add';

            try {
                DB::transaction(function () use ($row, $handler, $batch, $by, $decision) {
                    // The row is claimed under a lock: a second request that
                    // somehow reached it too finds it done and moves on.
                    $locked = ImportRow::query()->whereKey($row->id)->lockForUpdate()->first();
                    if ($locked === null || ! in_array($locked->status, [ImportRow::READY, ImportRow::DUPLICATE], true)) {
                        return;
                    }

                    $saved = $handler->save($this->values($batch, $locked), $decision, $locked->match_id, $batch, $by);
                    $locked->update([
                        'status' => $saved['status'],
                        'record_type' => $saved['type'],
                        'record_id' => $saved['id'],
                        'extra' => ($saved['extra'] ?? []) ?: null,
                        'message' => isset($saved['message']) ? mb_substr($saved['message'], 0, 255) : null,
                    ]);
                });
            } catch (\Throwable $e) {
                $plain = $e instanceof RuntimeException || $e instanceof \DomainException || $e instanceof \InvalidArgumentException;
                if (! $plain) {
                    report($e);
                }
                $row->update([
                    'status' => ImportRow::FAILED,
                    'message' => mb_substr($plain ? $e->getMessage() : 'This row could not be saved. Check it and import it again on its own.', 0, 255),
                ]);
            }
        }

        if (! $batch->rows()->whereIn('status', [ImportRow::READY, ImportRow::DUPLICATE])->exists()) {
            $this->finish($batch, ImportStatus::Importing, ['status' => ImportStatus::Done, 'finished_at' => now()]);
        }
        $this->recount($batch);
    }

    // ── Undoing ───────────────────────────────────────────────────────

    /** Why the batch cannot be undone now, or null when it can. */
    public function undoBlocker(ImportBatch $batch): ?string
    {
        return match (true) {
            $batch->status !== ImportStatus::Done => 'Only a finished import can be undone.',
            $batch->undoUntil() === null || $batch->undoUntil()->isPast() => 'An import can be undone for '.ImportBatch::UNDO_HOURS.' hours after it finished.',
            ! $batch->rows()->where('status', ImportRow::ADDED)->exists() && ! $this->updatesWithAdditions($batch)->exists() => 'This import added nothing that could be taken back.',
            default => null,
        };
    }

    public function undo(ImportBatch $batch, ?int $by): ImportBatch
    {
        $this->exclusive($batch, fn () => DB::transaction(function () use ($batch, $by) {
            $this->claim($batch, fn (ImportStatus $s) => $s === ImportStatus::Done, 'Only a finished import can be undone.');
            if (($why = $this->undoBlocker($batch)) !== null) {
                throw new RuntimeException($why);
            }
            $batch->update(['status' => ImportStatus::Undoing, 'undone_by' => $by]);
        }));

        return $batch;
    }

    private function undoChunk(ImportBatch $batch, ?int $by): void
    {
        $handler = $this->handler($batch->kind);
        $rows = $batch->rows()->where('status', ImportRow::ADDED)->orderByDesc('line')->limit(self::UNDO_CHUNK)->get();

        foreach ($rows as $row) {
            try {
                DB::transaction(function () use ($row, $handler, $by) {
                    $locked = ImportRow::query()->whereKey($row->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->status !== ImportRow::ADDED) {
                        return;
                    }
                    if (($why = $handler->undoBlocker($locked)) !== null) {
                        $locked->update(['status' => ImportRow::KEPT, 'message' => 'Kept: '.$why]);

                        return;
                    }
                    $handler->undo($locked, $by);
                    $locked->update(['status' => ImportRow::UNDONE, 'message' => null]);
                });
            } catch (\Throwable $e) {
                $plain = $e instanceof RuntimeException || $e instanceof \DomainException;
                if (! $plain) {
                    report($e);
                }
                $row->update(['status' => ImportRow::KEPT, 'message' => mb_substr('Kept: '.($plain ? $e->getMessage() : 'it could not be taken back.'), 0, 255)]);
            }
        }

        // Rows that updated a record but also added something beside it (a
        // balance brought forward): that addition is taken back, the update stays.
        if ($rows->count() < self::UNDO_CHUNK) {
            foreach ($this->updatesWithAdditions($batch)->limit(self::UNDO_CHUNK - $rows->count())->get() as $row) {
                DB::transaction(function () use ($row, $handler, $by) {
                    $locked = ImportRow::query()->whereKey($row->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->status !== ImportRow::UPDATED || ! isset($locked->extra['invoice_id'])) {
                        return;
                    }
                    $why = $handler->undoUpdate($locked, $by);
                    $locked->update([
                        'extra' => ['taken_back_invoice_id' => $why === null ? $locked->extra['invoice_id'] : null, 'kept_invoice_id' => $why === null ? null : $locked->extra['invoice_id']],
                        'message' => mb_substr($why === null ? 'Updated; the balance brought forward was taken back.' : 'Updated; the balance brought forward was kept: '.$why, 0, 255),
                    ]);
                });
            }
        }

        if (! $batch->rows()->where('status', ImportRow::ADDED)->exists() && ! $this->updatesWithAdditions($batch)->exists()) {
            $this->finish($batch, ImportStatus::Undoing, ['status' => ImportStatus::Undone, 'undone_at' => now()]);
        }
        $this->recount($batch);
    }

    /**
     * Updated rows that also added a record beside their changes, not yet taken back.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ImportRow, ImportBatch>
     */
    private function updatesWithAdditions(ImportBatch $batch): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $batch->rows()->where('status', ImportRow::UPDATED)->where('extra', 'like', '%"invoice_id"%');
    }

    /** Throw away an import that was never saved. */
    public function cancel(ImportBatch $batch): void
    {
        $this->exclusive($batch, fn () => DB::transaction(function () use ($batch) {
            $this->claim($batch, fn (ImportStatus $s) => $s->isDraft(), 'This import has been saved; undo it instead.');
            $batch->rows()->delete();
            $batch->update(['status' => ImportStatus::Cancelled]);
        }));
    }

    /** @param array<string,mixed> $changes */
    private function finish(ImportBatch $batch, ImportStatus $from, array $changes): void
    {
        ImportBatch::query()->whereKey($batch->id)->where('status', $from->value)->update(array_map(
            fn ($v) => $v instanceof ImportStatus ? $v->value : $v,
            $changes,
        ));
        $batch->refresh();
    }

    /** @return array<string,int> row status => how many */
    public function counts(ImportBatch $batch): array
    {
        return $batch->rows()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
    }

    private function recount(ImportBatch $batch): void
    {
        $c = $this->counts($batch);
        $batch->update([
            'added_count' => ($c[ImportRow::ADDED] ?? 0) + ($c[ImportRow::UNDONE] ?? 0) + ($c[ImportRow::KEPT] ?? 0),
            'updated_count' => $c[ImportRow::UPDATED] ?? 0,
            'skipped_count' => ($c[ImportRow::SKIPPED] ?? 0) + ($c[ImportRow::PROBLEM] ?? 0),
            'failed_count' => $c[ImportRow::FAILED] ?? 0,
            'undone_count' => $c[ImportRow::UNDONE] ?? 0,
        ]);
    }
}
