<?php

declare(strict_types=1);

use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Exceptions\InvalidPhoneNumber;

it('normalises every accepted Bangladeshi format to E.164', function (string $input) {
    expect(PhoneNumber::parse($input)->e164())->toBe('+8801712345678');
})->with([
    '01712345678', '8801712345678', '+8801712345678', '008801712345678',
    '+880 1712-345678', '(017) 1234 5678', '০১৭১২৩৪৫৬৭৮', '+৮৮০১৭১২৩৪৫৬৭৮', "  01712345678\n",
]);

it('rejects invalid Bangladeshi numbers', function (string $input) {
    PhoneNumber::parse($input);
})->with(['01212345678', '0171234567', '017123456789', '1712345678', 'abc', '', '+8801012345678', '+14155550123'])
    ->throws(InvalidPhoneNumber::class);

it('accepts non-Bangladeshi numbers only when enabled', function () {
    expect(PhoneNumber::parse('+14155550123', allowInternational: true)->e164())->toBe('+14155550123')
        ->and(PhoneNumber::tryParse('+14155550123'))->toBeNull()
        ->and(PhoneNumber::parse('+14155550123', true)->isBangladeshi())->toBeFalse();
});

it('does not let the international path bypass Bangladeshi prefix rules', function () {
    expect(PhoneNumber::tryParse('+8801212345678', allowInternational: true))->toBeNull();
});

it('offers national, masked and equality helpers', function () {
    $phone = PhoneNumber::parse('+8801712345678');

    expect($phone->national())->toBe('01712345678')
        ->and($phone->masked())->toBe('+88017****5678')
        ->and((string) $phone)->toBe('+8801712345678')
        ->and($phone->equals(PhoneNumber::parse('01712345678')))->toBeTrue()
        ->and($phone->masked())->not->toContain('1234');
});
