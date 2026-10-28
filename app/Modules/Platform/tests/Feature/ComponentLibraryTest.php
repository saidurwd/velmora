<?php

declare(strict_types=1);

use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Money;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;

beforeEach(fn () => $this->seed(LanguageSeeder::class));

it('renders the component library in both languages', function (string $locale, string $needle) {
    $this->get("/{$locale}/dev/components")->assertOk()->assertSee($needle)->assertSee('lang="'.$locale.'"', false);
})->with([['en', 'Component library'], ['bn', 'কম্পোনেন্ট লাইব্রেরি']]);

it('shows prices with the sale reduction readable by screen readers, and hides empty ratings', function () {
    $html = Blade::render('<x-platform::price :amount="$a" :compare-at="$b" /><x-platform::rating :value="0" :count="0" />', [
        'a' => Money::of(185000), 'b' => Money::of(220000),
    ]);

    expect($html)->toContain('line-through')->toContain('sr-only')->not->toContain('★');
});

it('never shows a compare-at price that is not higher than the price', function () {
    $m = Money::of(100000);

    expect(Blade::render('<x-platform::price :amount="$a" :compare-at="$b" />', ['a' => $m, 'b' => $m]))->not->toContain('line-through');
});

it('marks the current breadcrumb item and escapes labels', function () {
    $html = Blade::render('<x-platform::breadcrumb :items="$i" />', ['i' => [['label' => 'Home', 'url' => '/'], ['label' => '<script>x</script>']]]);

    expect($html)->toContain('aria-current="page"')->not->toContain('<script>');
});

it('paginates with an accessible, localised control', function () {
    $html = (string) (new LengthAwarePaginator([], 120, 12, 3, ['path' => '/x']))->links();

    expect($html)->toContain('aria-label')->toContain('aria-current="page"')->toContain('rel="prev"')->toContain('rel="next"');
});

it('uses the brand colour tokens, not hard-coded colours, in components', function () {
    $files = glob(app_path('Modules/Platform/resources/views/components/*.blade.php')) ?: [];

    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toMatch('/#[0-9a-fA-F]{6}\b/', basename($file));
    }
});
