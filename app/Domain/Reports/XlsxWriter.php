<?php

namespace App\Domain\Reports;

use RuntimeException;
use ZipArchive;

/**
 * Classeur Excel (.xlsx) minimal, sans dépendance : une feuille, textes en
 * chaînes (jamais de formule, donc rien ne s'exécute à l'ouverture), montants
 * en nombres avec séparateur de milliers.
 */
final class XlsxWriter
{
    private const int STYLE_TEXT = 0;

    private const int STYLE_BOLD = 1;

    private const int STYLE_NUMBER = 2;

    private const int STYLE_BOLD_NUMBER = 3;

    private const int STYLE_TITLE = 4;

    private const int STYLE_HEADER = 5;

    public function build(TabularReport $report, string $company): string
    {
        $rows = [];
        $rows[] = [[$company, self::STYLE_BOLD]];
        $rows[] = [[$report->title, self::STYLE_TITLE]];
        $rows[] = [[$report->subtitle, self::STYLE_TEXT]];
        $rows[] = [];

        foreach ($report->sections as $section) {
            if ($section['title'] !== null) {
                $rows[] = [[$section['title'], self::STYLE_BOLD]];
            }

            if ($section['headers'] !== []) {
                $rows[] = array_map(fn (string $h): array => [$h, self::STYLE_HEADER], $section['headers']);
            }

            foreach ($section['rows'] as $i => $row) {
                $strong = in_array($i, $section['strong'], true);
                $rows[] = array_map(fn (int|string|null $v): array => [$v, is_int($v) ? ($strong ? self::STYLE_BOLD_NUMBER : self::STYLE_NUMBER) : ($strong ? self::STYLE_BOLD : self::STYLE_TEXT)], $row);
            }

            $rows[] = [];
        }

        return $this->zip($this->sheet($rows, $report->maxColumns()), $this->sheetName($report->title));
    }

    /**
     * @param  list<list<array{0: int|string|null, 1: int}>>  $rows
     */
    private function sheet(array $rows, int $columns): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>';

        for ($c = 1; $c <= $columns; $c++) {
            $xml .= '<col min="'.$c.'" max="'.$c.'" width="'.($c === 1 ? 44 : 18).'" customWidth="1"/>';
        }

        $xml .= '</cols><sheetData>';

        foreach ($rows as $r => $cells) {
            $xml .= '<row r="'.($r + 1).'">';

            foreach ($cells as $c => [$value, $style]) {
                if ($value === null || $value === '') {
                    continue;
                }

                $ref = $this->column($c).($r + 1);
                $xml .= is_int($value)
                    ? '<c r="'.$ref.'" s="'.$style.'"><v>'.$value.'</v></c>'
                    : '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->escape((string) $value).'</t></is></c>';
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function zip(string $sheet, string $sheetName): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Classeur impossible à créer.');
        }

        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $zip->addFromString('[Content_Types].xml', $head.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $head.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$this->escape($sheetName).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', $head.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="6"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="3" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    /** A, B, … Z, AA… */
    private function column(int $index): string
    {
        $name = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    /** Nom de feuille : 31 caractères au plus, sans les signes interdits par Excel. */
    private function sheetName(string $title): string
    {
        $name = trim((string) preg_replace('/[\\\\\/\?\*\[\]:]+/u', ' ', $title));

        return mb_substr($name === '' ? 'Rapport' : $name, 0, 31);
    }

    private function escape(string $text): string
    {
        // Caractères de contrôle interdits en XML, puis échappement.
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
