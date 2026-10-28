<?php

declare(strict_types=1);

/** @return array<string, array<string, string>> locale dir => [relative file => path] */
function langFiles(): array
{
    $roots = array_merge([base_path('lang')], glob(app_path('Modules/*/resources/lang')) ?: []);
    $found = [];

    foreach ($roots as $root) {
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $localeDir) {
            foreach (glob($localeDir.'/*.php') ?: [] as $file) {
                $found[basename($localeDir)][str_replace(base_path().'/', '', $root).'/'.basename($file)] = $file;
            }
        }
    }

    return $found;
}

/** @param array<string, mixed> $array */
function flattenKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        $full = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        is_array($value) ? $keys = array_merge($keys, flattenKeys($value, $full)) : $keys[$full] = $value;
    }

    return $keys;
}

it('has identical translation keys in every supported locale (BD-34)', function () {
    $files = langFiles();
    $locales = array_keys($files);

    expect($locales)->toContain('en', 'bn');

    $names = array_unique(array_merge(...array_map(fn ($l) => array_map(fn ($p) => preg_replace('#/(en|bn)/#', '/', $p), array_keys($files[$l])), $locales)));

    foreach ($names as $name) {
        $perLocale = [];
        foreach ($locales as $locale) {
            $path = collect($files[$locale])->first(fn ($p, $rel) => preg_replace('#/(en|bn)/#', '/', $rel) === $name);
            $perLocale[$locale] = $path ? array_keys(flattenKeys(require $path)) : null;
        }

        foreach ($locales as $locale) {
            expect($perLocale[$locale])->not->toBeNull("{$name} missing for locale {$locale}");
        }

        $reference = $perLocale['en'];
        foreach ($locales as $locale) {
            expect(array_diff($reference, $perLocale[$locale]))->toBe([], "{$name}: keys missing in {$locale}")
                ->and(array_diff($perLocale[$locale], $reference))->toBe([], "{$name}: extra keys in {$locale}");
        }
    }
});

it('has no empty translation values', function () {
    foreach (langFiles() as $locale => $files) {
        foreach ($files as $rel => $path) {
            foreach (flattenKeys(require $path) as $key => $value) {
                expect(trim((string) $value))->not->toBe('', "{$locale} {$rel} {$key} is empty");
            }
        }
    }
});

it('contains Bangla script in the Bangla files (not copied English)', function () {
    foreach (langFiles()['bn'] as $rel => $path) {
        $text = implode(' ', flattenKeys(require $path));
        expect(preg_match('/\p{Bengali}/u', $text))->toBe(1, "{$rel} has no Bangla text");
    }
});

it('has no hard-coded user-visible text in Blade views', function () {
    $views = array_merge(
        glob(resource_path('views').'/{,*/,*/*/}*.blade.php', GLOB_BRACE) ?: [],
        glob(app_path('Modules/*/resources/views').'/{,*/,*/*/}*.blade.php', GLOB_BRACE) ?: [],
    );
    $allow = ['resources/views/dev/']; // living component page (non-production) may show sample text
    $offenders = [];

    foreach ($views as $view) {
        $rel = str_replace(base_path().'/', '', $view);
        if (collect($allow)->contains(fn ($a) => str_starts_with($rel, $a)) || str_ends_with($rel, 'welcome.blade.php')) {
            continue;
        }

        $html = (string) file_get_contents($view);
        $html = str_replace(['?->', '->', '=>'], ' ', $html); // PHP arrows inside attributes must not close a tag early
        $html = preg_replace('/@php\b.*?@endphp/s', '', $html);
        $html = preg_replace('/@props\s*\(\[.*?\]\)/s', '', $html);
        $html = preg_replace('/\{\{--.*?--\}\}/s', '', $html);
        $html = preg_replace('/\{!!.*?!!\}|\{\{.*?\}\}/s', ' ', $html);
        $html = preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('/@\w+\b\s*(\([^\n]*\))?/', ' ', $html); // any Blade directive
        $text = trim((string) preg_replace('/<[^>]*>/s', ' ', $html));
        $text = trim((string) preg_replace('/&[a-z]+;|&copy;|[\s\d\W]+/u', ' ', $text));

        if (preg_match('/\p{L}{2,}/u', $text)) {
            $offenders[$rel] = mb_substr($text, 0, 60);
        }
    }

    expect($offenders)->toBe([]);
});
