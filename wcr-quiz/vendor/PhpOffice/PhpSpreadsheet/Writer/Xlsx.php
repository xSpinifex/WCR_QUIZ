<?php

namespace PhpOffice\PhpSpreadsheet\Writer;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

class Xlsx
{
    /** @var Spreadsheet */
    private $spreadsheet;

    public function __construct(Spreadsheet $spreadsheet)
    {
        $this->spreadsheet = $spreadsheet;
    }

    public function save($filename)
    {
        $worksheet = $this->spreadsheet->getActiveSheet();
        $cells = $worksheet->getCellCollection();
        $sheetData = '';

        foreach ($cells as $rowNumber => $columns) {
            $sheetData .= '<row r="' . $rowNumber . '">';
            foreach ($columns as $columnNumber => $value) {
                $cellRef = $this->columnStringFromNumber($columnNumber) . $rowNumber;
                $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                $sheetData .= '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
            }
            $sheetData .= '</row>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetData>' . $sheetData . '</sheetData>'
            . '</worksheet>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>'
            . '<sheet name="' . htmlspecialchars($worksheet->getTitle(), ENT_XML1 | ENT_COMPAT, 'UTF-8') . '" sheetId="1" r:id="rId1" />'
            . '</sheets>'
            . '</workbook>';

        $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml" />'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml" />'
            . '</Relationships>';

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml" />'
            . '</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml" />'
            . '<Default Extension="xml" ContentType="application/xml" />'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml" />'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml" />'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml" />'
            . '</Types>';

        $tmpFile = tempnam(sys_get_temp_dir(), 'wcrq_xlsx_');
        if ($tmpFile === false) {
            throw new \RuntimeException('Unable to create temporary file for XLSX export.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to open temporary XLSX archive.');
        }

        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $rootRelsXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/styles.xml', $stylesXml);
        $zip->close();

        $data = file_get_contents($tmpFile);
        @unlink($tmpFile);
        if ($data === false) {
            throw new \RuntimeException('Unable to read generated XLSX data.');
        }

        if ($filename === 'php://output') {
            $stream = fopen($filename, 'wb');
            if (!$stream) {
                throw new \RuntimeException('Unable to open output stream for XLSX export.');
            }
            fwrite($stream, $data);
            fclose($stream);
        } else {
            $result = file_put_contents($filename, $data);
            if ($result === false) {
                throw new \RuntimeException('Unable to write XLSX file.');
            }
        }
    }

    private function columnStringFromNumber($columnNumber)
    {
        $columnNumber = (int) $columnNumber;
        if ($columnNumber < 1) {
            throw new \InvalidArgumentException('Column number must be a positive integer.');
        }

        $result = '';
        while ($columnNumber > 0) {
            $modulo = ($columnNumber - 1) % 26;
            $result = chr(65 + $modulo) . $result;
            $columnNumber = (int) (($columnNumber - $modulo) / 26);
        }

        return $result;
    }
}
