<?php

namespace App\Support\Imports;

use RuntimeException;
use ZipArchive;

/**
 * A small .xlsx workbook written by hand: the import templates (docs/imports.md)
 * and the monthly reports (docs/reports.md).
 *
 * Text by default, the first row bold, columns sized to fit. A sheet may say
 * which of its columns are money or whole numbers (`types`), and those cells
 * are written as numbers a spreadsheet can add up. Rows may be any iterable:
 * each sheet is written to a temporary file as it is read, so a month of
 * twenty thousand lines never sits in memory as one string. No library
 * needed, so it works on any host that can zip.
 */
final class XlsxWriter
{
    /** Rows read to size the columns of a sheet given as a stream. */
    private const SIZE_FROM = 200;

    /**
     * @param  list<array{name:string,rows:iterable<int, list<string|int|null>>,types?:list<string>}>  $sheets
     * @return string the workbook's bytes
     */
    public static function make(array $sheets): string
    {
        $file = tempnam(sys_get_temp_dir(), 'tdx');
        if ($file === false) {
            throw new RuntimeException('Could not prepare the template.');
        }

        // Every temporary file goes, whatever happens: a report whose rows
        // fail half-way must not leave a month of patients in /tmp.
        $parts = [];
        $zip = new ZipArchive;
        $open = false;
        try {
            if ($zip->open($file, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not prepare the template.');
            }
            $open = true;

            $count = count($sheets);
            $zip->addFromString('[Content_Types].xml', self::contentTypes($count));
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>');
            $zip->addFromString('xl/workbook.xml', self::workbook($sheets));
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels($count));
            $zip->addFromString('xl/styles.xml', self::styles());
            foreach ($sheets as $i => $sheet) {
                $parts[] = $part = self::sheet($sheet['rows'], $sheet['types'] ?? []);
                $zip->addFile($part, 'xl/worksheets/sheet'.($i + 1).'.xml');
            }
            $zip->close();
            $open = false;

            return (string) file_get_contents($file);
        } finally {
            if ($open) {
                @$zip->close();
            }
            foreach ($parts as $part) {
                @unlink($part);
            }
            @unlink($file);
        }
    }

    private static function contentTypes(int $count): string
    {
        $sheets = '';
        for ($i = 1; $i <= $count; $i++) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheets.'</Types>';
    }

    /** @param list<array{name:string,rows:list<list<string>>}> $sheets */
    private static function workbook(array $sheets): string
    {
        $xml = '';
        foreach ($sheets as $i => $sheet) {
            $name = self::esc(mb_substr((string) preg_replace('/[\[\]\*\?\/\\\\:]/', '', $sheet['name']), 0, 31));
            $xml .= '<sheet name="'.$name.'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$xml.'</sheets></workbook>';
    }

    private static function workbookRels(int $count): string
    {
        $xml = '';
        for ($i = 1; $i <= $count; $i++) {
            $xml .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }
        $xml .= '<Relationship Id="rId'.($count + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$xml.'</Relationships>';
    }

    private static function styles(): string
    {
        // Style 0: normal text. Style 1: bold, for the heading row. Style 2: text format (@), so a phone keeps its 0.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            // Style 3: money, #,##0.00. Style 4: a whole number.
            .'<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            .'</styleSheet>';
    }

    /**
     * The sheet's XML, written to a temporary file as its rows are read.
     *
     * Column widths come before the data in the file, so they are sized from
     * the first rows (all of them for a short sheet); the data goes to a
     * second file and is copied in behind them.
     *
     * @param  iterable<int, list<string|int|null>>  $rows
     * @param  list<string>  $types  per column: text (default), money or int
     * @return string the file's path
     */
    private static function sheet(iterable $rows, array $types): string
    {
        $dataFile = tempnam(sys_get_temp_dir(), 'tdr');
        $sheetFile = tempnam(sys_get_temp_dir(), 'tds');
        if ($dataFile === false || $sheetFile === false) {
            throw new RuntimeException('Could not prepare the spreadsheet.');
        }

        $data = $in = $out = false;
        try {
            $data = fopen($dataFile, 'wb');
            if ($data === false) {
                throw new RuntimeException('Could not prepare the spreadsheet.');
            }

            $widths = [];
            $r = 0;
            foreach ($rows as $row) {
                $line = '<row r="'.($r + 1).'">';
                foreach ($row as $c => $cell) {
                    $text = (string) ($cell ?? '');
                    if ($r < self::SIZE_FROM) {
                        $widths[$c] = max($widths[$c] ?? 10, min(60, mb_strlen($text) + 2));
                    }
                    $line .= self::cell(self::column($c).($r + 1), $text, $r === 0 ? 'head' : ($types[$c] ?? 'text'));
                }
                fwrite($data, $line.'</row>');
                $r++;
            }
            fclose($data);
            $data = false;

            $cols = '';
            foreach ($widths as $i => $w) {
                // Every column is text-formatted, so 0772… keeps its leading zero when typed in.
                $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" style="2" customWidth="1"/>';
            }

            $out = fopen($sheetFile, 'wb');
            $in = fopen($dataFile, 'rb');
            if ($out === false || $in === false) {
                throw new RuntimeException('Could not prepare the spreadsheet.');
            }
            fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
                .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
                .'<sheetData>');
            stream_copy_to_stream($in, $out);
            fwrite($out, '</sheetData></worksheet>');
        } catch (\Throwable $e) {
            @unlink($sheetFile);

            throw $e;
        } finally {
            foreach ([$data, $in, $out] as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
            @unlink($dataFile);
        }

        return $sheetFile;
    }

    /** One cell: text as an inline string, money and whole numbers as numbers. */
    private static function cell(string $ref, string $text, string $type): string
    {
        $numeric = $text !== '' && preg_match('/^-?\d+(\.\d+)?$/', $text) === 1;

        if ($numeric && ($type === 'money' || $type === 'int')) {
            return '<c r="'.$ref.'" s="'.($type === 'money' ? 3 : 4).'"><v>'.$text.'</v></c>';
        }

        return '<c r="'.$ref.'" t="inlineStr" s="'.($type === 'head' ? 1 : 2).'"><is><t xml:space="preserve">'.self::esc($text).'</t></is></c>';
    }

    /** 0 => A, 26 => AA. */
    private static function column(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod).$name;
            $index = intdiv($index - $mod, 26);
        }

        return $name;
    }

    private static function esc(string $text): string
    {
        // Control characters are not allowed in XML at all.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
