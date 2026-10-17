<?php

namespace App\Services\Compliance;

use App\Enums\DataExportStatus;
use App\Jobs\BuildHospitalExport;
use App\Models\DataExport;
use App\Models\Hospital;
use App\Models\User;
use App\Support\Compliance\TenantTables;
use App\Support\CurrentHospital;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * A hospital owns its data (docs/compliance/README.md, roadmap 07 §5).
 *
 * The owner asks from Settings; a queued job writes one CSV per table (only
 * this hospital's rows), the column list, and every uploaded file into a ZIP
 * on the private disk; the owner downloads it through an encrypted link that
 * expires, and every step is in the activity log.
 *
 * Built for shared hosting: rows are streamed a chunk at a time to temporary
 * files, nothing is shelled out, and the worker is the scheduler's own
 * short-lived `queue:work` (routes/console.php), so a standalone copy with
 * only a cron still gets its export.
 */
class HospitalExport
{
    private const DISK = 'local';

    private const CHUNK = 500;

    public function __construct(private readonly TenantTables $tables) {}

    /** Ask for an export. One at a time per hospital. */
    public function request(User $by): DataExport
    {
        $hospitalId = app(CurrentHospital::class)->id();
        // The hospital's own administrator: the only person the link will open for.
        if ($hospitalId === null || $by->hospital_id === null || (int) $by->hospital_id !== (int) $hospitalId) {
            throw ValidationException::withMessages(['export' => 'Only the hospital\'s own administrators can export its data.']);
        }

        $export = DB::transaction(function () use ($by, $hospitalId) {
            Hospital::query()->lockForUpdate()->findOrFail($hospitalId);
            if (DataExport::query()->whereIn('status', [DataExportStatus::Queued, DataExportStatus::Building])->exists()) {
                throw ValidationException::withMessages(['export' => 'An export is already being prepared. It will appear here when it is ready.']);
            }

            return DataExport::create([
                'uuid' => (string) Str::uuid(),
                'requested_by' => $by->id,
                'status' => DataExportStatus::Queued,
            ]);
        });

        activity()->causedBy($by)->performedOn($export)->log('data export requested');

        BuildHospitalExport::dispatch($export->id)->onConnection('data-exports')->onQueue('exports');

        return $export;
    }

    /** The job's work. Runs as the export's hospital whatever the process was. */
    public function build(DataExport $export): void
    {
        app(CurrentHospital::class)->within($export->hospital_id, fn () => $this->buildAs($export));
    }

    private function buildAs(DataExport $export): void
    {
        $hospitalId = $export->hospital_id;
        // Claimed in one statement: a second worker that picked the same job builds nothing.
        $claimed = DataExport::query()->withoutGlobalScopes()->whereKey($export->id)->where('status', DataExportStatus::Queued)
            ->update(['status' => DataExportStatus::Building, 'started_at' => now(), 'error' => null, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $export->refresh();

        $relative = 'exports/'.$hospitalId.'/'.$export->uuid.'.zip';
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory('exports/'.$hospitalId);
        $zipPath = $disk->path($relative);
        $work = storage_path('app/export-work/'.$export->uuid);
        File::ensureDirectoryExists($work, 0700);

        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the export file.');
            }

            $columns = [['table', 'column', 'type', 'nullable', 'note']];
            $rowsTotal = 0;
            $tablesCount = 0;

            // The hospital itself: one row, its settings without keys or secrets.
            $hospital = Hospital::query()->findOrFail($hospitalId);
            $hospitalRow = array_intersect_key($hospital->getAttributes(), array_flip(['id', 'uuid', 'name', 'slug', 'address', 'timezone', 'currency', 'status', 'created_at']));
            $hospitalRow['settings'] = json_encode(TenantTables::withoutSecrets((array) $hospital->settings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->writeCsv($work.'/hospital.csv', array_keys($hospitalRow), [array_values($hospitalRow)]);
            $zip->addFile($work.'/hospital.csv', 'tables/hospital.csv');
            foreach (array_keys($hospitalRow) as $c) {
                $columns[] = ['hospital', $c, $c === 'settings' ? 'json' : '', '', $c === 'settings' ? 'keys, secrets and passwords removed' : ''];
            }
            $tablesCount++;

            foreach ($this->tables->withColumn('hospital_id') as $table) {
                $keep = $this->tables->exportable($table);
                foreach ($this->tables->columns($table) as $c) {
                    $note = in_array($c['name'], TenantTables::OMIT, true) ? 'left out: secret'
                        : (in_array($table.'.'.$c['name'], TenantTables::ENCRYPTED, true) ? 'encrypted on the server; readable here' : '');
                    $columns[] = [$table, $c['name'], $c['type'], $c['nullable'] ? 'yes' : 'no', $note];
                }

                $rowsTotal += $this->exportTable($table, $keep, $hospitalId, $work.'/'.$table.'.csv');
                $zip->addFile($work.'/'.$table.'.csv', 'tables/'.$table.'.csv');
                $tablesCount++;
            }

            $this->writeCsv($work.'/_columns.csv', array_shift($columns), $columns);
            $zip->addFile($work.'/_columns.csv', '_columns.csv');

            $filesCount = $this->addFiles($zip, $zipPath, $hospital, $work);

            $zip->addFromString('README.txt', $this->readme($hospital, $tablesCount, $rowsTotal, $filesCount));

            if (! $zip->close()) {
                throw new \RuntimeException('Could not finish the export file.');
            }

            $export->update([
                'status' => DataExportStatus::Ready,
                'path' => $relative,
                'size_bytes' => (int) filesize($zipPath),
                'tables_count' => $tablesCount,
                'rows_count' => $rowsTotal,
                'files_count' => $filesCount,
                'finished_at' => now(),
                'expires_at' => now()->addHours((int) config('compliance.export_hours', 48)),
            ]);
            activity()->performedOn($export)->withProperties(['tables' => $tablesCount, 'rows' => $rowsTotal, 'files' => $filesCount])
                ->log('data export ready');
        } catch (\Throwable $e) {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
            $export->update(['status' => DataExportStatus::Failed, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            throw $e;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** @param list<string> $keep */
    private function exportTable(string $table, array $keep, int $hospitalId, string $file): int
    {
        $out = fopen($file, 'w');
        if ($out === false) {
            throw new \RuntimeException('Could not write '.$table.'.');
        }
        fputcsv($out, $keep);
        $count = 0;

        $write = function ($rows) use ($out, $table, $keep, &$count): void {
            foreach ($rows as $row) {
                $clean = $this->tables->clean($table, (array) $row);
                fputcsv($out, array_map(fn ($c) => is_scalar($clean[$c] ?? null) ? (string) $clean[$c] : '', $keep));
                $count++;
            }
        };

        // Raw table read: the global scope does not apply, so the hospital is named here.
        $query = DB::table($table)->select($keep)->where('hospital_id', $hospitalId);
        if (in_array('id', $keep, true)) {
            $query->chunkById(self::CHUNK, $write, 'id');
        } else {
            // No id: order by every column, so a page boundary cannot skip or repeat a row.
            foreach ($keep as $column) {
                $query->orderBy($column);
            }
            $query->chunk(self::CHUNK, $write);
        }
        fclose($out);

        return $count;
    }

    /** Every uploaded file the hospital's rows point at, under files/<disk>/<path>. */
    private function addFiles(ZipArchive $zip, string $zipPath, Hospital $hospital, string $work): int
    {
        $list = [['file', 'table', 'column', 'included']];
        $count = 0;
        $seen = [];

        $add = function (string $table, string $column, string $diskName, string $path) use ($zip, $zipPath, &$list, &$count, &$seen): void {
            $key = $diskName.'/'.ltrim($path, '/');
            if ($path === '' || isset($seen[$key]) || str_contains($path, '..')) {
                return;
            }
            $seen[$key] = true;
            $disk = Storage::disk($diskName);
            $included = $disk->exists($path);
            if ($included) {
                $zip->addFile($disk->path($path), 'files/'.$key);
                // Already compressed (photos, PDFs); storing them is faster and no bigger.
                $zip->setCompressionName('files/'.$key, ZipArchive::CM_STORE);
                $count++;
                // Written out every few hundred files: the zip library opens
                // every added file at close, and a shared host limits open files.
                if ($count % self::CHUNK === 0) {
                    if (! $zip->close() || $zip->open($zipPath) !== true) {
                        throw new \RuntimeException('Could not write the export file.');
                    }
                }
            }
            $list[] = ['files/'.$key, $table, $column, $included ? 'yes' : 'no: missing on the server'];
        };

        foreach (TenantTables::FILES as [$table, $column, $diskName]) {
            if (! $this->tables->has($table, $column) || ! $this->tables->has($table, 'hospital_id')) {
                continue;
            }
            DB::table($table)->where('hospital_id', $hospital->id)->whereNotNull($column)
                ->select(['id', $column])
                ->chunkById(self::CHUNK, function ($rows) use ($add, $table, $column, $diskName): void {
                    foreach ($rows as $row) {
                        $add($table, $column, $diskName, (string) ((array) $row)[$column]);
                    }
                });
        }
        if (filled($hospital->logo)) {
            $add('hospital', 'logo', 'public', (string) $hospital->logo);
        }

        $this->writeCsv($work.'/_files.csv', array_shift($list), $list);
        $zip->addFile($work.'/_files.csv', '_files.csv');

        return $count;
    }

    /**
     * @param  list<string>  $head
     * @param  list<list<mixed>>  $rows
     */
    private function writeCsv(string $file, array $head, array $rows): void
    {
        $out = fopen($file, 'w');
        if ($out === false) {
            throw new \RuntimeException('Could not write '.basename($file).'.');
        }
        fputcsv($out, $head);
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $row));
        }
        fclose($out);
    }

    private function readme(Hospital $hospital, int $tables, int $rows, int $files): string
    {
        return implode("\n", [
            'Full data export: '.$hospital->name,
            'Prepared '.now()->format('j F Y H:i').' ('.config('app.timezone').')',
            '',
            'tables/       one CSV file per table, only this hospital\'s rows ('.$tables.' tables, '.$rows.' rows).',
            '              The first line of each file is the column names. Dates are YYYY-MM-DD HH:MM:SS.',
            '              Amounts are plain numbers with two decimals, in the hospital\'s currency.',
            '_columns.csv  every column of every table: its type, whether it may be empty, and notes.',
            '              Passwords, two-step sign-in secrets and link keys are left out on purpose.',
            '              Card numbers and bank details, encrypted on the server, are readable here.',
            'files/        the uploaded files ('.$files.'): patient documents, results, photos and logos,',
            '              under the folder the system kept them in. _files.csv says which row each belongs to.',
            '',
            'Rows point at each other by id: for example visits.patient_id is an id in tables/patients.csv.',
            'Keep this file safe. It holds patients\' health records, which the Data Protection and',
            'Privacy Act 2019 treats as special personal data.',
            '',
        ]);
    }

    /** The download link: an encrypted token naming the export and when it stops working. */
    public function link(DataExport $export): string
    {
        $token = Crypt::encryptString((string) json_encode(['e' => $export->uuid, 'x' => $export->expires_at?->getTimestamp() ?? 0]));

        return route('admin.data-exports.download', ['token' => $token]);
    }

    /** The export a link names, if it still works for this person; null means "no such link". */
    public function open(string $token, User $user): ?DataExport
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($payload) || ! is_string($payload['e'] ?? null) || (int) ($payload['x'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        // Tenant-scoped: another hospital's export is not found, whoever holds the link.
        $export = DataExport::query()->where('uuid', $payload['e'])->first();
        // Compared as integers: some MySQL drivers hand ids back as strings.
        if ($export === null || ! $export->isDownloadable() || $user->hospital_id === null || (int) $export->hospital_id !== (int) $user->hospital_id) {
            return null;
        }
        if ($export->path === null || ! Storage::disk(self::DISK)->exists($export->path)) {
            return null;
        }

        return $export;
    }

    public function recordDownload(DataExport $export, User $by): void
    {
        DataExport::query()->whereKey($export->id)->update([
            'download_count' => DB::raw('download_count + 1'),
            'last_downloaded_at' => now(),
        ]);
        activity()->causedBy($by)->performedOn($export)->log('data export downloaded');
    }

    public function absolutePath(DataExport $export): string
    {
        return Storage::disk(self::DISK)->path((string) $export->path);
    }

    /** Delete expired export files, and give up on builds that died. Every hospital. */
    public function prune(): int
    {
        $n = 0;
        DataExport::query()->withoutGlobalScopes()
            ->where('status', DataExportStatus::Ready)->where('expires_at', '<', now())
            ->each(function (DataExport $export) use (&$n): void {
                if ($export->path !== null) {
                    Storage::disk(self::DISK)->delete($export->path);
                }
                $export->update(['status' => DataExportStatus::Expired, 'path' => null]);
                $n++;
            });

        DataExport::query()->withoutGlobalScopes()
            ->whereIn('status', [DataExportStatus::Queued, DataExportStatus::Building])
            ->where('updated_at', '<', now()->subHours(3))
            ->update(['status' => DataExportStatus::Failed, 'finished_at' => now(), 'error' => 'The export did not finish. Ask for a new one.']);

        $n += $this->sweep();

        return $n;
    }

    /**
     * Files no ready export points at, older than a few hours: the leftovers of
     * a worker killed mid-build (whose `finally` never ran) and the exports of
     * a hospital that has since been purged with its rows.
     */
    private function sweep(): int
    {
        $n = 0;
        $cutoff = now()->subHours(3)->getTimestamp();
        $disk = Storage::disk(self::DISK);
        $live = DataExport::query()->withoutGlobalScopes()->where('status', DataExportStatus::Ready)->whereNotNull('path')->pluck('path')->all();

        foreach ($disk->allFiles('exports') as $file) {
            if (! in_array($file, $live, true) && $disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $n++;
            }
        }
        $work = storage_path('app/export-work');
        foreach (File::isDirectory($work) ? File::directories($work) : [] as $dir) {
            if (filemtime($dir) < $cutoff) {
                File::deleteDirectory($dir);
                $n++;
            }
        }

        return $n;
    }
}
