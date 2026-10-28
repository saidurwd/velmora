<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Models\User;
use Illuminate\Support\Facades\Route;

it('redirects / to the best locale: default English, Accept-Language, then cookie', function () {
    $this->get('/')->assertRedirect('/en');
    $this->get('/', ['Accept-Language' => 'bn-BD,bn;q=0.9,en;q=0.8'])->assertRedirect('/bn');
    $this->get('/', ['Accept-Language' => 'fr-FR,fr;q=0.9'])->assertRedirect('/en');
    $this->withCookie('locale', 'bn')->get('/', ['Accept-Language' => 'en'])->assertRedirect('/bn');
});

it('serves every localized route under /en and /bn with the right lang attribute', function () {
    $this->get('/en')->assertOk()->assertSee('<html lang="en"', false)->assertSee('The store is being stocked');
    $this->get('/bn')->assertOk()->assertSee('<html lang="bn"', false)->assertSee('দোকানে পণ্য সাজানো হচ্ছে')->assertHeader('Content-Language', 'bn');
});

it('404s for unsupported locale prefixes', function () {
    $this->get('/fr')->assertNotFound();
});

it('remembers an explicit URL locale in a cookie that wins over Accept-Language later', function () {
    $response = $this->get('/bn', ['Accept-Language' => 'en']);
    $response->assertCookie('locale', 'bn');
});

it('lets the URL beat the cookie, and the cookie beat Accept-Language', function () {
    Route::get('/_t/where', fn () => app()->getLocale())->middleware('web');

    $this->withCookie('locale', 'bn')->get('/_t/where', ['Accept-Language' => 'en'])->assertSee('bn');
    $this->withCookie('locale', 'bn')->get('/en')->assertSee('<html lang="en"', false);
});

it('prefers a signed-in user locale over cookie and Accept-Language', function () {
    Route::get('/_t/where', fn () => app()->getLocale())->middleware('web');
    $user = User::factory()->create(['locale' => 'bn']);

    $this->actingAs($user)->withCookie('locale', 'en')->get('/_t/where', ['Accept-Language' => 'en'])->assertSee('bn');
});

it('saves the chosen language on the signed-in user when they switch via URL', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)->get('/bn')->assertOk();

    expect($user->fresh()->locale)->toBe('bn');
});

it('renders a switcher that keeps path and query', function () {
    Route::localized(fn () => Route::get('/products', fn () => view('layouts.storefront'))->name('t.products'));

    $html = $this->get('/en/products?page=2&sort=price')->assertOk()->getContent();

    expect($html)->toContain('href="'.rtrim(config('app.url'), '/').'/bn/products?page=2&amp;sort=price"')
        ->toContain('hreflang="bn"')
        ->toContain('বাংলা')
        ->toContain('aria-current="true"');
});

it('emits hreflang alternates including x-default and a canonical URL', function () {
    $html = $this->get('/bn')->getContent();

    expect($html)->toContain('<link rel="alternate" hreflang="en" href="'.rtrim(config('app.url'), '/').'/en">')
        ->toContain('<link rel="alternate" hreflang="bn" href="'.rtrim(config('app.url'), '/').'/bn">')
        ->toContain('<link rel="alternate" hreflang="x-default" href="'.rtrim(config('app.url'), '/').'/en">')
        ->toContain('<link rel="canonical" href="'.rtrim(config('app.url'), '/').'/bn">');
});

it('keeps only meaningful params in the canonical URL', function () {
    Route::localized(fn () => Route::get('/products', fn () => view('layouts.storefront')));

    $html = $this->get('/en/products?utm_source=x&page=2&fbclid=abc')->getContent();

    expect($html)->toContain('<link rel="canonical" href="'.rtrim(config('app.url'), '/').'/en/products?page=2">');
});

it('switches language on non-prefixed pages through a POST that sets the cookie and goes back safely', function () {
    $response = $this->from('/en')->post('/en/locale', ['locale' => 'bn']);

    $response->assertRedirect('/bn')->assertCookie('locale', 'bn');
});

it('never redirects off-site from the locale switch', function () {
    $this->from('https://evil.example/phish')->post('/en/locale', ['locale' => 'bn'])->assertRedirect('/');
});

it('rejects unsupported locales on the switch endpoint', function () {
    $this->post('/en/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');
});

it('renders 404 pages in the visitor language for localized URLs', function () {
    $this->get('/bn/nothing-here')->assertNotFound()->assertSee('পেজ পাওয়া যায়নি');
    $this->get('/en/nothing-here')->assertNotFound()->assertSee('Page not found');
});

it('answers with Vary so caches keep languages apart', function () {
    expect($this->get('/en')->headers->get('Vary'))->toContain('Accept-Language');
});
