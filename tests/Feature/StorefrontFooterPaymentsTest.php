<?php

declare(strict_types=1);

use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;

/*
 * The footer's "We accept" list names only the ways to pay the shop has switched on, so it never promises a method a customer then cannot use.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class]);
});

/** The footer's "We accept" block of a page, or '' when the page has none. */
function weAccept(string $html): string
{
    return preg_match('#We accept</p>\s*<ul[^>]*>(.*?)</ul>#s', $html, $m) === 1 ? $m[1] : '';
}

it('lists the payment methods that are switched on, and drops one that is switched off', function () {
    DB::table('payment_methods')->update(['is_enabled' => true]);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake']);   // online payment is only offered once a gateway is set up
    $on = weAccept((string) $this->get('/en')->assertOk()->getContent());
    expect($on)->toContain('Cash on delivery')->toContain('Pay online');

    DB::table('payment_methods')->where('code', 'online')->update(['is_enabled' => false]);
    cache()->flush();   // the footer is cached for at most two minutes on a real shop
    $off = weAccept((string) $this->get('/en')->assertOk()->getContent());
    expect($off)->toContain('Cash on delivery')->not->toContain('Pay online');
});

it('shows nothing under "We accept" when no method is switched on', function () {
    DB::table('payment_methods')->update(['is_enabled' => false]);

    expect($this->get('/en')->assertOk()->getContent())->not->toContain('We accept');
});
