<?php

namespace App\Reports\Export;

/**
 * Lays report rows out for Excel like a pivot table in tabular form
 * (Report::excelGrouping()): consecutive rows with the same group values form
 * a group; the group values show on its first row only, the other rows leave
 * them empty; a bold "<value> Total" row after each group adds up the sum
 * columns; the group's rows are an outline level, so Excel can collapse a
 * group to its total. A grand total of every group closes the sheet; its
 * unit is named only when every row shares one.
 *
 * Rows come in the report's own order, which keeps a group's rows together.
 */
final class GroupedRows
{
    /**
     * @param  list<list<string|int|float|null>>  $rows  sheet rows (without the header), visible columns only
     * @param  list<int>  $details  sheet row numbers (1 = header) of the rows inside a group: outline level 1
     * @param  list<int>  $totals  sheet row numbers of the total rows (group totals and the grand total): bold
     * @param  list<int>  $firsts  sheet row numbers of each group's first row: its group cells bold
     * @param  list<int>  $groupColumns  0-based indexes of the group columns
     */
    private function __construct(
        public readonly array $rows,
        public readonly array $details,
        public readonly array $totals,
        public readonly array $firsts,
        public readonly array $groupColumns,
    ) {}

    /**
     * @param  list<string>  $keys  visible column keys, in sheet order
     * @param  iterable<array<string, string|int|float|null>>  $rows  Excel values keyed by column (every column, hidden ones too)
     * @param  array{group: list<string>, sum: list<string>, unit?: string}  $grouping
     */
    public static function build(array $keys, iterable $rows, array $grouping): self
    {
        $groupKeys = array_values(array_filter($keys, fn (string $key) => in_array($key, $grouping['group'], true)));
        $sumKeys = array_values(array_filter($keys, fn (string $key) => in_array($key, $grouping['sum'], true)));
        $unitKey = isset($grouping['unit']) && in_array($grouping['unit'], $keys, true) ? $grouping['unit'] : null;
        $groupColumns = array_map(fn (string $key) => (int) array_search($key, $keys, true), $groupKeys);

        // Sheet rows; the next one is sheet row count($out) + 2 (row 1 is the header).
        $out = [];
        $details = $totals = $firsts = [];
        $line = fn (array $cells) => array_map(fn (string $key) => $cells[$key] ?? '', $keys);

        // No group column left visible: a plain sheet.
        if ($groupKeys === []) {
            foreach ($rows as $cells) {
                $out[] = $line($cells);
            }

            return new self($out, [], [], [], []);
        }

        $groups = [];
        foreach ($rows as $cells) {
            // The group is every group column, hidden ones too, so that two
            // warehouses' rows of one item never run together.
            $id = implode("\x1F", array_map(fn (string $key) => (string) ($cells[$key] ?? ''), $grouping['group']));

            if ($groups === [] || $groups[array_key_last($groups)]['id'] !== $id) {
                $groups[] = ['id' => $id, 'rows' => []];
            }

            $groups[array_key_last($groups)]['rows'][] = $cells;
        }

        $grand = array_fill_keys($sumKeys, 0.0);
        $allUnits = [];

        foreach ($groups as $group) {
            $sums = array_fill_keys($sumKeys, 0.0);
            $units = [];

            foreach ($group['rows'] as $i => $cells) {
                $row = $line($cells);

                if ($i === 0) {
                    $firsts[] = count($out) + 2;
                } else {
                    foreach ($groupColumns as $column) {
                        $row[$column] = '';
                    }
                }

                foreach ($sumKeys as $key) {
                    $sums[$key] += (float) ($cells[$key] ?? 0);
                }

                if ($unitKey !== null) {
                    $units[(string) ($cells[$unitKey] ?? '')] = true;
                }

                $details[] = count($out) + 2;
                $out[] = $row;
            }

            $first = $group['rows'][0];
            $total = array_fill_keys($keys, '');
            $total[$groupKeys[0]] = __('reports.export.group_total', ['value' => (string) ($first[$groupKeys[0]] ?? '')]);

            foreach ($sumKeys as $key) {
                $total[$key] = $sums[$key];
                $grand[$key] += $sums[$key];
            }

            if ($unitKey !== null) {
                $total[$unitKey] = count($units) === 1 ? (string) array_key_first($units) : '';
                $allUnits += $units;
            }

            $totals[] = count($out) + 2;
            $out[] = array_values($total);
        }

        // Grand total: the total of the group totals, always.
        if ($groups !== [] && $sumKeys !== []) {
            $total = array_fill_keys($keys, '');
            $total[$groupKeys[0]] = __('reports.export.grand_total');

            foreach ($sumKeys as $key) {
                $total[$key] = $grand[$key];
            }

            if ($unitKey !== null) {
                // Several units: the sum, without a unit name.
                $total[$unitKey] = count($allUnits) === 1 ? (string) array_key_first($allUnits) : '';
            }

            $totals[] = count($out) + 2;
            $out[] = array_values($total);
        }

        return new self($out, $details, $totals, $firsts, $groupColumns);
    }
}
