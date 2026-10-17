<?php

namespace App\Support\Diagnoses;

use App\Models\DiagnosisCode;

/**
 * Loads the diagnosis catalogue: the shipped list (database/data/icd10_common.php)
 * on every deploy, and a full WHO list from a CSV when a hospital has one.
 * Upserts by system and code, so a re-run changes titles and synonyms and
 * never duplicates; codes are never deleted (old visits keep pointing at them).
 */
final class Catalogue
{
    /** @return int rows written */
    public static function syncShipped(): int
    {
        /** @var list<array{0:string,1:string,2?:list<string>}> $rows */
        $rows = require database_path('data/icd10_common.php');

        return self::write(array_map(fn (array $r) => [$r[0], $r[1], $r[2] ?? []], $rows));
    }

    /**
     * A CSV of code,title[,synonym;synonym]: the WHO list or a ministry's.
     *
     * @return int rows written
     */
    public static function importCsv(string $path, string $system = 'icd10'): int
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            $code = strtoupper(trim((string) ($line[0] ?? '')));
            $title = trim((string) ($line[1] ?? ''));
            if ($code === '' || $title === '' || preg_match('/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/', $code) !== 1) {
                continue; // a header row, or not a code
            }
            $rows[] = [$code, $title, array_values(array_filter(array_map('trim', explode(';', (string) ($line[2] ?? '')))))];
        }
        fclose($handle);

        return self::write($rows, $system, keepSynonyms: true);
    }

    /** @param list<array{0:string,1:string,2:list<string>}> $rows */
    private static function write(array $rows, string $system = 'icd10', bool $keepSynonyms = false): int
    {
        $n = 0;
        foreach ($rows as [$code, $title, $synonyms]) {
            $existing = DiagnosisCode::query()->where('system', $system)->where('code', $code)->first();
            if ($keepSynonyms && $existing !== null) {
                $synonyms = array_values(array_unique(array_merge((array) $existing->synonyms, $synonyms)));
            }
            DiagnosisCode::query()->updateOrCreate(['system' => $system, 'code' => $code], [
                'title' => $title,
                'synonyms' => $synonyms,
                'search' => self::searchText($code, $title, $synonyms),
                'is_active' => true,
            ]);
            $n++;
        }

        return $n;
    }

    /** @param list<string> $synonyms */
    public static function searchText(string $code, string $title, array $synonyms): string
    {
        return mb_strtolower(' '.$code.' '.$title.' '.implode(' ', $synonyms).' ');
    }
}
