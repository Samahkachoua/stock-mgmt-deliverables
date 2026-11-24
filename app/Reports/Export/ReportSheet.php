<?php

namespace App\Reports\Export;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One worksheet: a header row, then one row per report row. Quantities are
 * numbers (the cell's `value`), everything else the text shown on screen.
 * Arabic exports are right-to-left. A grouped sheet (GroupedRows) gets
 * collapsible groups, bold totals and filter buttons on its header.
 */
final class ReportSheet implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
        private readonly bool $rtl,
        // Grouped like a pivot table (Report::excelGrouping()): $rows are its rows.
        private readonly ?GroupedRows $grouped = null,
    ) {}

    /**
     * @return list<list<string|int|float|null>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        // Excel sheet names: at most 31 characters, none of []:*?/\
        return mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $this->title), 0, 31);
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->setRightToLeft($this->rtl);
                $sheet->getStyle('1:1')->getFont()->setBold(true);
                $sheet->freezePane('A2');

                if ($this->grouped !== null) {
                    $this->applyGroups($sheet);
                }
            },
        ];
    }

    private function applyGroups(Worksheet $sheet): void
    {
        $grouped = $this->grouped;
        $lastRow = count($this->rows) + 1;
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($this->headings)));

        $sheet->setAutoFilter('A1:'.$lastColumn.$lastRow);
        // The 'Total' row under each group carries its collapse button.
        $sheet->setShowSummaryBelow(true);

        foreach ($grouped->details as $row) {
            $sheet->getRowDimension($row)->setOutlineLevel(1);
        }

        foreach ($grouped->totals as $row) {
            $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getFont()->setBold(true);
        }

        foreach ($grouped->firsts as $row) {
            foreach ($grouped->groupColumns as $column) {
                $sheet->getStyle(Coordinate::stringFromColumnIndex($column + 1).$row)->getFont()->setBold(true);
            }
        }
    }
}
