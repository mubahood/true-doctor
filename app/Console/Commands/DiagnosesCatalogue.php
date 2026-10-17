<?php

namespace App\Console\Commands;

use App\Support\Diagnoses\Catalogue;
use Illuminate\Console\Command;

/**
 * The diagnosis catalogue: `diagnoses:catalogue` loads the shipped list (run
 * on every deploy; safe to repeat), `--import=file.csv` adds a full list
 * (code,title[,synonym;synonym]) such as the WHO ICD-10.
 */
class DiagnosesCatalogue extends Command
{
    protected $signature = 'diagnoses:catalogue {--import= : A CSV of code,title[,synonyms] to load on top}';

    protected $description = 'Load the diagnosis catalogue (ICD-10), and optionally a full list from a CSV';

    public function handle(): int
    {
        $this->info('Shipped list: '.Catalogue::syncShipped().' codes.');
        if ($path = $this->option('import')) {
            $this->info('Imported: '.Catalogue::importCsv((string) $path).' codes from '.$path.'.');
        }

        return self::SUCCESS;
    }
}
