<?php

declare(strict_types=1);

use App\Modules\Platform\Support\Slugger;

it('slugifies English text', function () {
    expect(Slugger::make('  Men\'s Cotton T-Shirt (XL)!  '))->toBe('men-s-cotton-t-shirt-xl');
});

it('keeps Bangla letters and combining marks intact', function () {
    expect(Slugger::make('পুরুষদের সুতির শার্ট'))->toBe('পুরুষদের-সুতির-শার্ট')
        ->and(Slugger::make('ক্ষ'))->toBe('ক্ষ'); // conjunct made with a virama must not be split
});

it('maps Bangla digits to ASCII and ignores zero-width characters', function () {
    expect(Slugger::make('মডেল ১২৩'))->toBe('মডেল-123')
        ->and(Slugger::make("ক\u{200C}ষ"))->toBe('কষ');
});

it('normalises equivalent spellings to one slug', function () {
    expect(Slugger::make("\u{09A1}\u{09BC}"))->toBe(Slugger::make("\u{09DC}"));
});

it('truncates long slugs without leaving a trailing dash', function () {
    $slug = Slugger::make(str_repeat('word ', 60));

    expect(mb_strlen($slug))->toBeLessThanOrEqual(120)->and($slug)->not->toEndWith('-');
});

it('returns an empty string when nothing slug-worthy remains', function () {
    expect(Slugger::make('!!! ---'))->toBe('');
});
