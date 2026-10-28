<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/** schema.org JSON-LD builders shared by every module. Output goes through PageSeo::encode(), which cannot be broken out of. */
final class StructuredData
{
    /**
     * @param  list<array{label: string, url: string|null}>  $crumbs  the last crumb may have no URL (the current page)
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $crumbs, string $currentUrl): array
    {
        $items = [];
        foreach ($crumbs as $i => $crumb) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['label'], 'item' => $crumb['url'] ?? $currentUrl];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /** @return array<string, mixed> */
    public static function organization(Branding $brand, string $homeUrl): array
    {
        return array_filter(['@type' => 'Organization', 'name' => $brand->name(), 'url' => $homeUrl, 'logo' => $brand->logoUrl()], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    public static function website(Branding $brand, string $homeUrl, string $searchUrl): array
    {
        return [
            '@type' => 'WebSite', 'name' => $brand->name(), 'url' => $homeUrl,
            'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $searchUrl.'?q={search_term_string}'], 'query-input' => 'required name=search_term_string'],
        ];
    }

    /** Absolute URL for a stored file URL (S3 gives absolute URLs; a local disk gives a path). */
    public static function absolute(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
