<?php

namespace App\Support\Imports;

use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Reads the first sheet of an .xlsx workbook, or a .csv file, into rows of
 * text (docs/imports.md).
 *
 * Pure PHP on purpose: a clinic's own server is often shared hosting with no
 * shell, and the standalone release ships vendor/ as it is. An .xlsx is a zip
 * of XML, which ZipArchive and XMLReader (both already required) read a row at
 * a time, so a 10,000-row sheet does not need the memory of a spreadsheet
 * library. Cells formatted as dates come back as "YYYY-MM-DD" (with " HH:MM"
 * when they carry a time), numbers as Excel shows them without grouping.
 *
 * The old binary .xls format is not read; the message says how to save the
 * sheet as .xlsx or .csv instead.
 */
final class SpreadsheetReader
{
    /** More than this many rows is a job to split, not a sheet to import. */
    public const MAX_ROWS = 20000;

    /** The most a sheet (or its text) may unpack to. */
    private const MAX_UNPACKED_BYTES = 150 * 1024 * 1024;

    /** The most the workbook's small parts (sheet list, styles) may unpack to. */
    private const MAX_PART_BYTES = 10 * 1024 * 1024;

    /** Columns beyond this are not read. */
    private const MAX_COLUMNS = 1000;

    /** Excel's built-in number formats that show a date or a time. */
    private const DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];

    /**
     * @return array<int, list<string>> sheet row number => cells, empty rows left out
     */
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($ext) {
            'xlsx' => self::xlsx($path),
            'csv', 'txt' => self::csv($path),
            'xls' => throw new RuntimeException('This is an old Excel file (.xls), which cannot be read here. In Excel choose File, Save as, and pick "Excel Workbook (.xlsx)" or "CSV", then upload that file.'),
            default => throw new RuntimeException('Upload an Excel workbook (.xlsx) or a CSV file.'),
        };
    }

    /** @return array<int, list<string>> */
    private static function csv(string $path): array
    {
        $path = self::asUtf8($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The file could not be opened. Try uploading it again.');
        }

        $first = (string) fgets($handle);
        rewind($handle);
        $delimiter = self::delimiter($first);

        $rows = [];
        $line = 0;
        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($line === 1 && isset($cells[0])) {
                $cells[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
            }
            $cells = array_map(fn ($c) => self::text((string) $c), $cells);
            if (self::isEmpty($cells)) {
                continue;
            }
            $rows[$line] = $cells;
            self::guardSize($rows);
        }
        fclose($handle);

        return $rows;
    }

    /** A UTF-16 file (Excel's "Unicode text") rewritten as UTF-8; anything else as it is. */
    private static function asUtf8(string $path): string
    {
        $head = (string) file_get_contents($path, false, null, 0, 2);
        $from = match ($head) {
            "\xFF\xFE" => 'UTF-16LE',
            "\xFE\xFF" => 'UTF-16BE',
            default => null,
        };
        if ($from === null) {
            return $path;
        }

        $out = tempnam(sys_get_temp_dir(), 'imp16');
        if ($out === false) {
            throw new RuntimeException('The file could not be opened. Try uploading it again.');
        }
        file_put_contents($out, mb_convert_encoding(substr((string) file_get_contents($path), 2), 'UTF-8', $from));

        return $out;
    }

    private static function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** @return array<int, list<string>> */
    private static function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('This file is not a readable Excel workbook. Open it in Excel, save it again as .xlsx, and upload it.');
        }

        try {
            $sheet = self::firstSheetPath($zip);
            $stat = $zip->statName($sheet);
            if ($stat === false) {
                throw new RuntimeException('The workbook has no sheet to read.');
            }
            // A sheet that unpacks to more than this is not a clinic's list.
            if ((int) $stat['size'] > self::MAX_UNPACKED_BYTES) {
                throw new RuntimeException('This workbook is too large to read. Split it into smaller sheets and import them one after another.');
            }
            $strings = self::sharedStrings($zip, $path);
            $dateStyles = self::dateStyles($zip);
            $from1904 = self::uses1904($zip);
        } finally {
            $zip->close();
        }

        // Streamed straight out of the zip, a row at a time: the sheet itself
        // is never held in memory whole.
        $reader = new XMLReader;
        if (! @$reader->open('zip://'.$path.'#'.$sheet, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('The workbook could not be read. Save it again as .xlsx and upload it.');
        }

        $rows = [];
        $current = null;
        $line = 0;
        $cells = [];

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                $line = (int) ($reader->getAttribute('r') ?: $line + 1);
                $cells = [];
                if ($reader->isEmptyElement) {
                    continue;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row') {
                if (! self::isEmpty($cells)) {
                    $max = max(array_keys($cells));
                    $list = [];
                    for ($i = 0; $i <= $max; $i++) {
                        $list[] = $cells[$i] ?? '';
                    }
                    $rows[$line] = $list;
                    self::guardSize($rows);
                }
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                $ref = (string) $reader->getAttribute('r');
                $type = (string) $reader->getAttribute('t');
                $style = (int) $reader->getAttribute('s');
                $index = $ref !== '' ? self::columnIndex($ref) : count($cells);
                $current = ['index' => $index, 'type' => $type, 'style' => $style, 'value' => ''];
                // Nothing a clinic keeps runs past column ALL; a cell out there is ignored.
                if ($reader->isEmptyElement || $index >= self::MAX_COLUMNS) {
                    $current = null;
                }
            } elseif ($current !== null && $reader->nodeType === XMLReader::ELEMENT && ($reader->localName === 'v' || $reader->localName === 't')) {
                $current['value'] .= $reader->readString();
            } elseif ($current !== null && $reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c') {
                $cells[$current['index']] = self::cellText($current, $strings, $dateStyles, $from1904);
                $current = null;
            }
        }
        $reader->close();

        return $rows;
    }

    /**
     * @param  array{index:int,type:string,style:int,value:string}  $cell
     * @param  list<string>  $strings
     * @param  array<int,bool>  $dateStyles
     */
    private static function cellText(array $cell, array $strings, array $dateStyles, bool $from1904 = false): string
    {
        $value = $cell['value'];

        return self::text(match ($cell['type']) {
            's' => $strings[(int) $value] ?? '',
            'b' => $value === '1' ? 'Yes' : 'No',
            'inlineStr', 'str', 'e' => $value,
            default => is_numeric($value) && isset($dateStyles[$cell['style']]) ? self::serialToDate((float) $value, $from1904) : self::number($value),
        });
    }

    /**
     * An Excel day number as a date, with the time when it has one: days
     * since 30 December 1899, or since 1 January 1904 in a workbook saved
     * with the 1904 date system (some made on a Mac).
     */
    public static function serialToDate(float $serial, bool $from1904 = false): string
    {
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        $date = (new \DateTimeImmutable($from1904 ? '1904-01-01' : '1899-12-30'))->modify("+{$days} days")->modify("+{$seconds} seconds");

        return $seconds > 0 ? $date->format('Y-m-d H:i') : $date->format('Y-m-d');
    }

    /** 2.5E-2 and 1043.0 as people wrote them: 0.025 and 1043. */
    private static function number(string $value): string
    {
        if (! is_numeric($value)) {
            return $value;
        }
        if (str_contains(strtolower($value), 'e')) {
            $value = rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');
        }
        if (str_contains($value, '.')) {
            // Floating point noise from Excel: 25000.000000001 is 25000.
            $value = rtrim(rtrim(number_format(round((float) $value, 6), 6, '.', ''), '0'), '.');
        }

        return $value;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = self::part($zip, 'xl/workbook.xml');
        $rels = self::part($zip, 'xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $book = self::xml($workbook);
        $links = self::xml($rels);
        if ($book === null || $links === null) {
            return 'xl/worksheets/sheet1.xml';
        }

        $book->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheets = $book->xpath('//m:sheets/m:sheet');
        $first = $sheets[0] ?? null;
        if ($first === null) {
            return 'xl/worksheets/sheet1.xml';
        }
        $id = (string) ($first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');

        foreach ($links->children() as $rel) {
            if ((string) $rel['Id'] === $id) {
                $target = ltrim((string) $rel['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** A small part of the workbook (its list of sheets, its styles), read whole: false when missing or too large to be one. */
    private static function part(ZipArchive $zip, string $name): string|false
    {
        $stat = $zip->statName($name);
        if ($stat === false || (int) $stat['size'] > self::MAX_PART_BYTES) {
            return false;
        }

        return $zip->getFromName($name);
    }

    private static function uses1904(ZipArchive $zip): bool
    {
        $workbook = self::part($zip, 'xl/workbook.xml');

        return $workbook !== false && preg_match('/<(?:\w+:)?workbookPr\b[^>]*\bdate1904="(?:1|true)"/', $workbook) === 1;
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip, string $path): array
    {
        $stat = $zip->statName('xl/sharedStrings.xml');
        if ($stat === false) {
            return [];
        }
        if ((int) $stat['size'] > self::MAX_UNPACKED_BYTES) {
            throw new RuntimeException('This workbook is too large to read. Split it into smaller sheets and import them one after another.');
        }

        $reader = new XMLReader;
        if (! @$reader->open('zip://'.$path.'#xl/sharedStrings.xml', null, LIBXML_NONET | LIBXML_COMPACT)) {
            return [];
        }

        $strings = [];
        $text = null;
        $inPhonetic = false;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $text = '';
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'rPh') {
                $inPhonetic = ! $reader->isEmptyElement;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'rPh') {
                $inPhonetic = false;
            } elseif ($text !== null && ! $inPhonetic && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                $text .= $reader->readString();
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
                $strings[] = (string) $text;
                $text = null;
            }
        }
        $reader->close();

        return $strings;
    }

    /** @return array<int,bool> style index => shows a date */
    private static function dateStyles(ZipArchive $zip): array
    {
        $xml = self::part($zip, 'xl/styles.xml');
        $styles = $xml === false ? null : self::xml($xml);
        if ($styles === null) {
            return [];
        }

        $custom = [];
        foreach ($styles->numFmts->numFmt ?? [] as $fmt) {
            // A format with day, month or year letters outside quotes and brackets.
            $code = (string) preg_replace('/"[^"]*"|\[[^\]]*\]/', '', strtolower((string) $fmt['formatCode']));
            if (preg_match('/[dmy]/', $code) === 1 && preg_match('/^[#0.,% ]+$/', $code) !== 1) {
                $custom[(int) $fmt['numFmtId']] = true;
            }
        }

        $out = [];
        $i = 0;
        foreach ($styles->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            if (in_array($id, self::DATE_FORMATS, true) || isset($custom[$id])) {
                $out[$i] = true;
            }
            $i++;
        }

        return $out;
    }

    private static function xml(string $text): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($text, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }

    /** "AB12" => 27 (zero-based column). */
    public static function columnIndex(string $ref): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $ref));
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }

    /** Trimmed, in UTF-8 even when the CSV was saved by an old Excel in Windows-1252. */
    private static function text(string $value): string
    {
        if ($value !== '' && ! mb_check_encoding($value, 'UTF-8')) {
            $value = (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return trim(str_replace("\u{00A0}", ' ', $value));
    }

    /** @param array<int,string> $cells */
    private static function isEmpty(array $cells): bool
    {
        foreach ($cells as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, list<string>> $rows */
    private static function guardSize(array $rows): void
    {
        if (count($rows) > self::MAX_ROWS + 1) {
            throw new RuntimeException('This sheet has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller sheets and import them one after another.');
        }
    }
}
