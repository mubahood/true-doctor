<?php

namespace Database\Seeders\DemoScenarios;

use App\Enums\ImportKind;
use App\Models\Hospital;
use App\Models\ImportBatch;
use App\Models\Patient;
use App\Models\User;
use App\Services\DataImportService;
use App\Support\CurrentHospital;

/**
 * Settings, Import data in the demo hospital (docs/imports.md): one import of
 * the clinic's old patient register, finished a week and a half ago, so the
 * log has something in it and the imported patients carry their old file
 * numbers, allergies, next of kin and, for one, a balance brought forward.
 *
 * Run through DataImportService exactly as the screens run it: one row looks
 * like a patient already registered (and is skipped), one has a date nobody
 * can read (and is left out). Safe to run twice: the import is recognised by
 * its file name.
 */
class Imports
{
    private const FILE = 'old-patient-register.csv';

    public static function run(Hospital $hospital): void
    {
        app(CurrentHospital::class)->within($hospital->id, function () use ($hospital): void {
            if (ImportBatch::query()->where('file_name', self::FILE)->exists()) {
                return;
            }
            $admin = User::query()->where('hospital_id', $hospital->id)->where('role', 'hospital_admin')->orderBy('id')->first();
            if ($admin === null) {
                return;
            }

            $born = fn (int $years, int $days) => now()->subYears($years)->subDays($days)->format('d/m/Y');
            $year = (int) now()->format('Y');
            $rows = [
                ['File No', 'Names', 'Sex', 'DOB', 'Age', 'Tel', 'Next of kin', 'NOK phone', 'Allergies', 'Conditions', 'Balance'],
                ['OLD 0412', 'Ssemwogerere Joseph', 'M', $born(58, 130), '', '0784 610 221', 'Nansubuga Rose (wife)', '0772 610 222', '', 'Hypertension', '45,000'],
                ['OLD 0413', 'Namuli Esther', 'F', $born(34, 30), '', '0784 610 233', 'Kato Brian (husband)', '0701 610 234', 'Penicillin', '', ''],
                ['OLD 0414', 'Nakimuli Precious', 'F', '', '7', '', 'Namuli Esther (mother)', '0784 610 233', '', 'Asthma', ''],
                ['OLD 0415', 'Okello Charles', 'M', $born(71, 270), '', '0784 610 245', 'Akello Grace (daughter)', '0772 610 246', 'Sulfa drugs', 'Type 2 diabetes, Hypertension', ''],
                ['OLD 0416', 'Atuhaire Brenda', 'F', '31/31/'.($year - 26), '', '0784 610 257', '', '', '', '', ''],
            ];

            // One row is somebody the clinic already has: same name, same phone.
            $known = Patient::query()->whereNotNull('phone_1')->orderBy('id')->first();
            if ($known !== null) {
                $rows[] = ['OLD 0417', $known->first_name.' '.$known->last_name, $known->sex?->value === 'female' ? 'F' : 'M', '', '', (string) $known->phone_1, '', '', '', '', ''];
            }

            $path = tempnam(sys_get_temp_dir(), 'demo-import');
            if ($path === false) {
                return;
            }
            $handle = fopen($path, 'wb');
            if ($handle === false) {
                return;
            }
            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '');
            }
            fclose($handle);

            $imports = app(DataImportService::class);
            $batch = $imports->start(ImportKind::Patients, $path, self::FILE, $admin->id);
            @unlink($path);
            $batch = $imports->map($batch, $batch->column_map ?? []);
            for ($i = 0; $i < 20 && $batch->status->isWorking(); $i++) {
                $batch = $imports->step($batch, $admin->id);
            }
            $batch = $imports->import($batch);
            for ($i = 0; $i < 20 && $batch->status->isWorking(); $i++) {
                $batch = $imports->step($batch, $admin->id);
            }

            // It happened a week and a half ago, so it can no longer be undone.
            $when = now()->subDays(11)->setTime(9, 40);
            $batch->forceFill([
                'created_at' => $when->copy()->subMinutes(6),
                'imported_at' => $when->copy()->subMinute(),
                'finished_at' => $when,
            ])->save();
        });
    }
}
