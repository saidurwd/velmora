<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services\Seo;

use App\Modules\Catalog\Contracts\SitemapSource;
use App\Modules\Catalog\DTOs\SitemapEntry;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the XML sitemaps: one index (`sitemap.xml`) and, per language, files of at most 10,000 addresses each. Every address
 * lists its alternates in the other languages (xhtml:link hreflang) so search engines pair the English and Bangla versions.
 * Files are written to private storage and served by SitemapController; the index is written last so a half-built set is never published.
 */
final class SitemapGenerator
{
    public const PER_FILE = 10_000;

    private const DIR = 'sitemaps';

    public function __construct(private readonly SitemapSource $catalog) {}

    /** @return int number of addresses written (counted once per language) */
    public function generate(): int
    {
        $disk = Storage::disk('local');
        $locales = array_values(array_map('strval', (array) config('app.supported_locales')));
        $default = (string) config('app.default_locale');
        $stamp = now('UTC')->format('YmdHis');

        /** @var array<string, array{n: int, count: int, xml: string}> $open one file being filled per language */
        $open = [];
        /** @var list<string> $written */
        $written = [];
        $total = 0;

        foreach ($this->entries($locales) as $entry) {
            foreach ($locales as $locale) {
                if (! isset($entry->paths[$locale])) {
                    continue;
                }
                if (isset($open[$locale]) && $open[$locale]['count'] >= self::PER_FILE) {
                    $written[] = $this->close($disk, $locale, $open[$locale], $stamp);
                    $open[$locale] = ['n' => $open[$locale]['n'] + 1, 'count' => 0, 'xml' => $this->open()];
                }
                $open[$locale] ??= ['n' => 1, 'count' => 0, 'xml' => $this->open()];
                $open[$locale]['xml'] .= $this->url($entry, $locale, $default);
                $open[$locale]['count']++;
                $total++;
            }
        }

        foreach ($open as $locale => $file) {
            $written[] = $this->close($disk, (string) $locale, $file, $stamp);
        }

        $index = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($written as $name) {
            $index .= '  <sitemap><loc>'.$this->e(url("/sitemaps/{$name}")).'</loc><lastmod>'.now('UTC')->format('Y-m-d\TH:i:sP').'</lastmod></sitemap>'."\n";
        }
        $index .= '</sitemapindex>'."\n";

        $disk->put(self::DIR.'/sitemap.xml', $index); // last: publishes the new set

        foreach ($disk->files(self::DIR) as $file) { // old generations no longer referenced
            if (basename($file) !== 'sitemap.xml' && ! in_array(basename($file), $written, true)) {
                $disk->delete($file);
            }
        }

        return $total;
    }

    /**
     * @param  list<string>  $locales
     * @return iterable<SitemapEntry>
     */
    private function entries(array $locales): iterable
    {
        yield new SitemapEntry(array_combine($locales, array_map(fn ($l) => "/{$l}", $locales)));
        yield from $this->catalog->entries();

        foreach (Page::query()->where('status', 'published')->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now('UTC')))->orderBy('id')->cursor() as $page) {
            $paths = [];
            foreach (PageTranslation::query()->where('page_id', $page->id)->get() as $t) {
                $paths[$t->locale] = "/{$t->locale}/pages/".rawurlencode($t->slug);
            }
            if ($paths !== []) {
                yield new SitemapEntry($paths, ($u = $page->getAttribute('updated_at')) === null ? null : CarbonImmutable::parse((string) $u, 'UTC'));
            }
        }
    }

    private function open(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
    }

    private function url(SitemapEntry $entry, string $locale, string $default): string
    {
        $xml = '  <url><loc>'.$this->e(url($entry->paths[$locale])).'</loc>';
        if ($entry->lastModified !== null) {
            $xml .= '<lastmod>'.$entry->lastModified->utc()->format('Y-m-d\TH:i:sP').'</lastmod>';
        }
        foreach ($entry->paths as $code => $path) {
            $xml .= '<xhtml:link rel="alternate" hreflang="'.$this->e($code).'" href="'.$this->e(url($path)).'"/>';
        }
        if (isset($entry->paths[$default])) {
            $xml .= '<xhtml:link rel="alternate" hreflang="x-default" href="'.$this->e(url($entry->paths[$default])).'"/>';
        }

        return $xml.'</url>'."\n";
    }

    /** @param  array{n: int, count: int, xml: string}  $file */
    private function close(Filesystem $disk, string $locale, array $file, string $stamp): string
    {
        $name = "sitemap-{$locale}-{$file['n']}-{$stamp}.xml";
        $disk->put(self::DIR.'/'.$name, $file['xml'].'</urlset>'."\n");

        return $name;
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
