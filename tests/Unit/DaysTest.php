<?php

use App\Support\Days;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('counts whole calendar days until a date in the warehouse time zone', function () {
    // 10:00 in Beirut; the expiry dates are plain dates.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 10:00', 'Asia/Beirut'));

    expect(Days::until('2026-10-21', 'Asia/Beirut'))->toBe(-1)
        ->and(Days::until('2026-10-17', 'Asia/Beirut'))->toBe(-5)
        ->and(Days::until('2026-10-22', 'Asia/Beirut'))->toBe(0)
        ->and(Days::until('2026-10-23', 'Asia/Beirut'))->toBe(1)
        ->and(Days::until('2026-11-21', 'Asia/Beirut'))->toBe(30);
});

it('uses the warehouse date, not the UTC date, just after midnight there', function () {
    // 00:30 on 22 Oct in Beirut is still 21 Oct in UTC.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 00:30', 'Asia/Beirut'));

    expect(Days::until('2026-10-22', 'Asia/Beirut'))->toBe(0)
        ->and(Days::until('2026-10-21', 'Asia/Beirut'))->toBe(-1);
});

it('is not thrown off by a daylight-saving change', function () {
    expect(Days::between(CarbonImmutable::parse('2026-10-20', 'Asia/Beirut'), '2026-10-30'))->toBe(10)
        ->and(Days::between('2027-03-20', CarbonImmutable::parse('2027-03-30', 'Asia/Beirut')))->toBe(10);
});
