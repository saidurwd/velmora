<?php

declare(strict_types=1);

use App\Modules\Platform\Support\BanglaText;

it('converts Bangla digits to ASCII and back', function () {
    expect(BanglaText::digitsToAscii('০১২৩৪৫৬৭৮৯ abc ১২'))->toBe('0123456789 abc 12')
        ->and(BanglaText::digitsToBangla('Order 2026-10'))->toBe('Order ২০২৬-১০');
});

it('normalises numeric fields', function () {
    expect(BanglaText::numeric(' ১২ ৩৪ '))->toBe('1234')
        ->and(BanglaText::numeric("\u{200B}০১৭"))->toBe('017');
});

it('normalises to NFC so equivalent Bangla spellings compare equal', function () {
    $decomposed = "\u{09A1}\u{09BC}"; // ড + nukta
    $precomposed = "\u{09DC}";       // ড় (composition-excluded, canonical equivalent)

    expect(BanglaText::nfc($precomposed))->toBe(BanglaText::nfc($decomposed));
});

it('strips zero-width characters only for search/numeric, never from stored input', function () {
    $zwnj = "ক\u{200C}ষ";

    expect(BanglaText::forSearch($zwnj))->toBe('কষ')
        ->and(BanglaText::input($zwnj))->toBe($zwnj);
});

it('builds a stable search key', function () {
    expect(BanglaText::forSearch("  Samsung   GALAXY\t১২  "))->toBe('samsung galaxy 12')
        ->and(BanglaText::forSearch('মোবাইল  ফোন'))->toBe('মোবাইল ফোন');
});

it('removes control characters from free text but keeps newlines and tabs', function () {
    expect(BanglaText::input("hello\x00 wor\x07ld\n"))->toBe('hello world');
});
