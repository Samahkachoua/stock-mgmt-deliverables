<?php

namespace App\Imports;

use App\Models\User;
use App\Reports\Export\ReportSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The template for an import, in the user's language: the first sheet has the
 * column headers ("*" = required), the second the codes to use (customers,
 * units, warehouses...), side by side.
 */
final class TemplateExport implements WithMultipleSheets
{
    public function __construct(
        private readonly ImportType $type,
        private readonly User $user,
    ) {}

    /**
     * @return list<ReportSheet>
     */
    public function sheets(): array
    {
        $rtl = app()->getLocale() === 'ar';
        $lists = $this->type->codeLists($this->user);

        // Two columns per list (code, name), an empty column between lists.
        $headings = [];
        $columns = [];
        foreach ($lists as $title => $codes) {
            array_push($headings, $title, __('imports.codes.name'), '');
            $columns[] = array_map(fn ($code, $name) => [(string) $code, (string) $name], array_keys($codes), $codes);
        }

        $rows = [];
        $length = max(array_map('count', $columns) ?: [0]);
        for ($i = 0; $i < $length; $i++) {
            $row = [];
            foreach ($columns as $list) {
                array_push($row, $list[$i][0] ?? '', $list[$i][1] ?? '', '');
            }
            $rows[] = $row;
        }

        return [
            new ReportSheet($this->type->title(), array_map(fn (Column $column) => $column->header(), $this->type->columns()), [], $rtl),
            new ReportSheet(__('imports.codes.sheet'), $headings, $rows, $rtl),
        ];
    }
}
