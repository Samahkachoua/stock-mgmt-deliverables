<?php

use App\Services\Stock\UnitConverter;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->units = new UnitConverter(['BOX' => 12, 'PLT' => 480], 'PC');
});

it('converts to the base unit', function () {
    expect($this->units->toBase(3, 'BOX'))->toBe(36.0)
        ->and($this->units->toBase('1.5', 'PLT'))->toBe(720.0)
        ->and($this->units->toBase(7, 'PC'))->toBe(7.0);
});

it('converts from the base unit', function () {
    expect($this->units->fromBase(36, 'BOX'))->toBe(3.0)
        ->and($this->units->fromBase(64, 'BOX'))->toBe(5.333333)
        ->and($this->units->fromBase(64, 'PC'))->toBe(64.0);
});

it('round-trips without drifting', function () {
    $units = new UnitConverter(['BAG' => 25.5], 'KG');

    expect($units->toBase($units->fromBase(51, 'BAG'), 'BAG'))->toBe(51.0);
});

it('breaks a quantity into whole larger units plus the rest', function (float $base, string $expected) {
    expect($this->units->breakdown($base))->toBe($expected);
})->with([
    [64, '5 Box + 4 Piece'],
    [480, '1 Pallet'],
    [1000, '2 Pallet + 3 Box + 4 Piece'],
    [11, '11 Piece'],
    [0, '0 Piece'],
    [-14, '-1 Box + 2 Piece'],
    [12.5, '1 Box + 0.5 Piece'],
]);

it('limits the breakdown to the units asked for', function () {
    expect($this->units->breakdown(1000, ['BOX']))->toBe('83 Box + 4 Piece')
        ->and($this->units->breakdown(1000, []))->toBe('1,000 Piece');
});

it('always treats the base unit as factor 1, largest units first', function () {
    expect((new UnitConverter(['PC' => 5, 'BOX' => 12], 'PC'))->factors())->toBe(['BOX' => 12.0, 'PC' => 1.0]);
});

it('refuses unknown units and factors below 1', function () {
    expect(fn () => $this->units->toBase(1, 'CTN'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new UnitConverter(['BOX' => 0.5], 'PC'))->toThrow(InvalidArgumentException::class);
});
