<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds .xlsx uploads for the import tests.
 */
final class Spreadsheets
{
    /**
     * A workbook whose first sheet has $headers then $rows. A cell given as
     * ['date' => 'Y-m-d'] is written as a real Excel date.
     *
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    public static function upload(array $headers, array $rows, string $name = 'items.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');

        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $cell = [$c + 1, $r + 2];

                if (is_array($value)) {
                    $sheet->setCellValue($cell, ExcelDate::PHPToExcel(new \DateTimeImmutable($value['date'])));
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                } else {
                    $sheet->setCellValueExplicit($cell, $value, is_int($value) || is_float($value)
                        ? DataType::TYPE_NUMERIC
                        : DataType::TYPE_STRING);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
    }
}
