<?php

use App\Reports\Export\GroupedRows;

/** Stock by expiry's grouping (StockByExpiryReport::excelGrouping()). */
const LOT_GROUPING = ['group' => ['code', 'item', 'customer', 'warehouse', 'storage'], 'sum' => ['qty', 'reserved', 'available'], 'unit' => 'unit'];

const LOT_KEYS = ['code', 'item', 'customer', 'warehouse', 'storage', 'expiry', 'unit', 'qty', 'reserved', 'available'];

/**
 * One lot row as the Exporter passes it (Excel values keyed by column).
 *
 * @return array<string, string|float>
 */
function lot(string $code, string $expiry, float $qty, float $reserved = 0, string $unit = 'Box', string $warehouse = 'Beirut Main', string $item = ''): array
{
    return [
        'code' => $code, 'item' => $item !== '' ? $item : 'Item '.$code, 'customer' => 'Acme', 'warehouse' => $warehouse, 'storage' => 'Frozen',
        'expiry' => $expiry, 'unit' => $unit, 'qty' => $qty, 'reserved' => $reserved, 'available' => $qty - $reserved,
    ];
}

it('shows the group values once, then a Total row per group and a grand total', function () {
    $grouped = GroupedRows::build(LOT_KEYS, [
        lot('34994', '02-08-2028', 40),
        lot('34994', '01-01-2028', 130, 10),
        lot('34994', '15-02-2028', 165),
        lot('34997', '02-08-2028', 9),
    ], LOT_GROUPING);

    expect($grouped->rows)->toBe([
        ['34994', 'Item 34994', 'Acme', 'Beirut Main', 'Frozen', '02-08-2028', 'Box', 40.0, 0.0, 40.0],
        ['', '', '', '', '', '01-01-2028', 'Box', 130.0, 10.0, 120.0],
        ['', '', '', '', '', '15-02-2028', 'Box', 165.0, 0.0, 165.0],
        ['34994 Total', '', '', '', '', '', 'Box', 335.0, 10.0, 325.0],
        ['34997', 'Item 34997', 'Acme', 'Beirut Main', 'Frozen', '02-08-2028', 'Box', 9.0, 0.0, 9.0],
        ['34997 Total', '', '', '', '', '', 'Box', 9.0, 0.0, 9.0],
        ['Grand total', '', '', '', '', '', 'Box', 344.0, 10.0, 334.0],
    ])
        // Sheet rows (1 is the header): the lots are the outline, the totals bold.
        ->and($grouped->details)->toBe([2, 3, 4, 6])
        ->and($grouped->totals)->toBe([5, 7, 8])
        ->and($grouped->firsts)->toBe([2, 6])
        ->and($grouped->groupColumns)->toBe([0, 1, 2, 3, 4]);
});

it('keeps one item in two warehouses as two groups, even with the warehouse column hidden', function () {
    $keys = array_values(array_diff(LOT_KEYS, ['warehouse']));
    $grouped = GroupedRows::build($keys, [
        lot('7712', '01-06-2028', 1387, warehouse: 'Beirut Main'),
        lot('7712', '01-07-2028', 36, warehouse: 'Tripoli'),
    ], LOT_GROUPING);

    expect(array_column($grouped->rows, 0))->toBe(['7712', '7712 Total', '7712', '7712 Total', 'Grand total'])
        ->and($grouped->rows[0])->toHaveCount(count($keys))
        ->and(array_column($grouped->rows, 6))->toBe([1387.0, 1387.0, 36.0, 36.0, 1423.0]);
});

it('adds a grand total of mixed units too, without naming a unit', function () {
    $grouped = GroupedRows::build(LOT_KEYS, [
        lot('A1', '01-01-2028', 5, unit: 'Box'),
        lot('A1', '02-01-2028', 3, unit: 'Piece'),
        lot('B1', '01-01-2028', 7, unit: 'Kilogram'),
    ], LOT_GROUPING);

    expect(array_column($grouped->rows, 0))->toBe(['A1', '', 'A1 Total', 'B1', 'B1 Total', 'Grand total'])
        ->and($grouped->rows[2][6])->toBe('')
        ->and($grouped->rows[4][6])->toBe('Kilogram')
        // The total of the totals: every quantity column, no unit name.
        ->and($grouped->rows[5])->toBe(['Grand total', '', '', '', '', '', '', 15.0, 0.0, 15.0])
        ->and($grouped->totals)->toBe([4, 6, 7]);
});

it('puts the Total label in the first group column left when the SKU is hidden', function () {
    $keys = array_values(array_diff(LOT_KEYS, ['code']));
    $grouped = GroupedRows::build($keys, [lot('7713', '01-06-2028', 762, item: 'Tenderloin 4/5'), lot('7713', '01-07-2028', 108, item: 'Tenderloin 4/5')], LOT_GROUPING);

    expect(array_column($grouped->rows, 0))->toBe(['Tenderloin 4/5', '', 'Tenderloin 4/5 Total', 'Grand total'])
        ->and($grouped->groupColumns)->toBe([0, 1, 2, 3]);
});

it('leaves out sums of hidden quantity columns', function () {
    $keys = array_values(array_diff(LOT_KEYS, ['reserved', 'available']));
    $grouped = GroupedRows::build($keys, [lot('7716', '01-06-2028', 465, 5), lot('7716', '01-07-2028', 45)], LOT_GROUPING);

    expect($grouped->rows[2])->toBe(['7716 Total', '', '', '', '', '', 'Box', 510.0]);
});

it('writes plain rows when no group column is shown', function () {
    $grouped = GroupedRows::build(['expiry', 'qty'], [lot('A', '01-01-2028', 1), lot('A', '02-01-2028', 2)], LOT_GROUPING);

    expect($grouped->rows)->toBe([['01-01-2028', 1.0], ['02-01-2028', 2.0]])
        ->and($grouped->details)->toBe([])
        ->and($grouped->totals)->toBe([]);
});

it('writes nothing for no rows', function () {
    $grouped = GroupedRows::build(LOT_KEYS, [], LOT_GROUPING);

    expect($grouped->rows)->toBe([])->and($grouped->totals)->toBe([]);
});

it('says Total in the user’s language', function () {
    app()->setLocale('ar');
    $ar = GroupedRows::build(LOT_KEYS, [lot('34994', '02-08-2028', 40)], LOT_GROUPING);
    app()->setLocale('fr');
    $fr = GroupedRows::build(LOT_KEYS, [lot('34994', '02-08-2028', 40)], LOT_GROUPING);

    expect(array_column($ar->rows, 0))->toBe(['34994', 'إجمالي 34994', 'الإجمالي العام'])
        ->and(array_column($fr->rows, 0))->toBe(['34994', 'Total 34994', 'Total général']);
});
