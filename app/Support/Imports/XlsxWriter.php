<?php

namespace App\Support\Imports;

use RuntimeException;
use ZipArchive;

/**
 * A small .xlsx workbook written by hand: the import templates (docs/imports.md).
 *
 * Text only, the first row bold, columns sized to fit. Enough for a template
 * a clinic fills in; no library needed, so it works on any host that can zip.
 */
final class XlsxWriter
{
    /**
     * @param  list<array{name:string,rows:list<list<string>>}>  $sheets
     * @return string the workbook's bytes
     */
    public static function make(array $sheets): string
    {
        $file = tempnam(sys_get_temp_dir(), 'tdx');
        if ($file === false) {
            throw new RuntimeException('Could not prepare the template.');
        }

        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not prepare the template.');
        }

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
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', self::sheet($sheet['rows']));
        }
        $zip->close();

        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
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
            .'<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            .'</styleSheet>';
    }

    /** @param list<list<string>> $rows */
    private static function sheet(array $rows): string
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 10, min(60, mb_strlen($cell) + 2));
            }
        }

        $cols = '';
        foreach ($widths as $i => $w) {
            // Every column is text-formatted, so 0772… keeps its leading zero when typed in.
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" style="2" customWidth="1"/>';
        }

        $data = '';
        foreach ($rows as $r => $row) {
            $data .= '<row r="'.($r + 1).'">';
            foreach ($row as $c => $cell) {
                $ref = self::column($c).($r + 1);
                $data .= '<c r="'.$ref.'" t="inlineStr" s="'.($r === 0 ? 1 : 2).'"><is><t xml:space="preserve">'.self::esc($cell).'</t></is></c>';
            }
            $data .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>'.$data.'</sheetData></worksheet>';
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
