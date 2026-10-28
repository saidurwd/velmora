<?php

declare(strict_types=1);

use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Exceptions\MoneyException;

it('adds and subtracts in minor units', function () {
    $a = Money::of(125000);

    expect($a->add(Money::of(50))->amountMinor)->toBe(125050)
        ->and($a->subtract(Money::of(125001))->amountMinor)->toBe(-1)
        ->and($a->subtract(Money::of(125001))->isNegative())->toBeTrue();
});

it('rejects mixed currencies', function () {
    Money::of(100, 'BDT')->add(Money::of(100, 'USD'));
})->throws(MoneyException::class, 'Currency mismatch');

it('rejects unsupported currencies', fn () => Money::of(1, 'XXX'))->throws(MoneyException::class);

it('detects overflow instead of silently becoming a float', function () {
    Money::of(PHP_INT_MAX)->add(Money::of(1));
})->throws(MoneyException::class, 'overflowed');

it('detects overflow on multiply', fn () => Money::of(PHP_INT_MAX)->multiply(2))->throws(MoneyException::class);

it('parses decimal strings exactly, including Bangla digits', function (string $in, int $minor) {
    expect(Money::fromMajorString($in)->amountMinor)->toBe($minor);
})->with([
    ['1250', 125000], ['1250.5', 125050], ['1,250.50', 125050], ['০.০৫', 5], ['১২৫০.৫০', 125050], ['-3.20', -320], ['0.1', 10],
]);

it('rejects bad or over-precise amounts', function (string $in) {
    Money::fromMajorString($in);
})->with(['abc', '1.234', '', '1..2'])->throws(MoneyException::class);

it('rounds ratios half away from zero exactly once', function (int $amount, int $n, int $d, int $expected) {
    expect(Money::of($amount)->multiplyRatio($n, $d)->amountMinor)->toBe($expected);
})->with([
    [100, 1, 3, 33], [100, 2, 3, 67], [5, 1, 2, 3], [-5, 1, 2, -3], [1, 1, 2, 1], [0, 7, 3, 0], [10000, 1500, 10000, 1500],
]);

it('computes percentages in basis points', function () {
    expect(Money::of(100000)->percentage(1500)->amountMinor)->toBe(15000)
        ->and(Money::of(999)->percentage(1500)->amountMinor)->toBe(150); // 149.85 -> 150
});

it('allocates without losing a paisa', function () {
    $parts = Money::of(100)->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m) => $m->amountMinor, $parts))->toBe([34, 33, 33]);
});

it('allocates negative amounts symmetrically', function () {
    $parts = Money::of(-100)->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m) => $m->amountMinor, $parts))->toBe([-34, -33, -33]);
});

it('never gives remainder units to zero-weight parts', function () {
    $parts = Money::of(5)->allocate([0, 1, 1]);

    expect(array_map(fn (Money $m) => $m->amountMinor, $parts))->toBe([0, 3, 2]);
});

it('keeps allocation sums exact for random amounts and weights (property test)', function () {
    mt_srand(20261028);

    for ($i = 0; $i < 2000; $i++) {
        $amount = mt_rand(-5_000_000, 5_000_000);
        $ratios = array_map(fn () => mt_rand(0, 50), range(1, mt_rand(1, 8)));

        if (array_sum($ratios) === 0) {
            $ratios[0] = 1;
        }

        $sum = array_sum(array_map(fn (Money $m) => $m->amountMinor, Money::of($amount)->allocate($ratios)));

        expect($sum)->toBe($amount);
    }
});

it('rejects invalid allocations', function (array $ratios) {
    Money::of(100)->allocate($ratios);
})->with([[[]], [[0, 0]], [[-1, 2]]])->throws(MoneyException::class);

it('formats for English with western grouping', function () {
    expect(Money::of(125000)->format('en'))->toBe('৳ 1,250.00')
        ->and(Money::of(125000000)->format('en'))->toBe('৳ 1,250,000.00')
        ->and(Money::of(5)->format('en'))->toBe('৳ 0.05')
        ->and(Money::of(-125000)->format('en'))->toBe('-৳ 1,250.00');
});

it('formats for Bangla with lakh/crore grouping and optional Bangla digits', function () {
    expect(Money::of(125000000)->format('bn'))->toBe('৳ 12,50,000.00')
        ->and(Money::of(1250000000000)->format('bn'))->toBe('৳ 12,50,00,00,000.00')
        ->and(Money::of(125000)->format('bn', banglaDigits: true))->toBe('৳ ১,২৫০.০০')
        ->and(Money::of(125000000)->format('bn', banglaDigits: true))->toBe('৳ ১২,৫০,০০০.০০');
});

it('serialises for the API', function () {
    expect(Money::of(125000)->jsonSerialize())->toBe(['amount_minor' => 125000, 'currency' => 'BDT', 'formatted' => '৳ 1,250.00']);
});

it('compares', function () {
    expect(Money::of(5)->equals(Money::of(5)))->toBeTrue()
        ->and(Money::of(5)->equals(Money::of(5, 'USD')))->toBeFalse()
        ->and(Money::of(6)->greaterThan(Money::of(5)))->toBeTrue()
        ->and(Money::of(4)->lessThan(Money::of(5)))->toBeTrue();
});

it('renders a plain major-unit string that parses back to the same amount', function () {
    foreach ([0, 5, 99, 100, 125050, -5, -125050] as $minor) {
        expect(Money::fromMajorString(Money::of($minor)->toMajorString())->amountMinor)->toBe($minor);
    }

    expect(Money::of(125050)->toMajorString())->toBe('1250.50')
        ->and(Money::of(5)->toMajorString())->toBe('0.05')
        ->and(Money::of(1000, 'JPY')->toMajorString())->toBe('1000');
});
