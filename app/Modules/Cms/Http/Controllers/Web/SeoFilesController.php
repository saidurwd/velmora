<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Web;

use App\Modules\Cms\Services\Seo\SitemapGenerator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/** robots.txt, the sitemap index and the sitemap files. */
class SeoFilesController
{
    public function robots(): Response
    {
        $lines = ['User-agent: *'];

        if (! app()->isProduction()) {
            $lines[] = 'Disallow: /'; // staging and local copies must never be indexed
        } else {
            array_push($lines,
                'Disallow: /admin', 'Disallow: /api/',
                'Disallow: /*/search', 'Disallow: /*/login', 'Disallow: /*/register', 'Disallow: /*/forgot-password', 'Disallow: /*/reset-password', 'Disallow: /*/account',
                'Allow: /', '', 'Sitemap: '.url('/sitemap.xml'));
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function index(SitemapGenerator $generator): Response
    {
        return $this->file('sitemaps/sitemap.xml', $generator);
    }

    public function show(string $file, SitemapGenerator $generator): Response
    {
        abort_unless(preg_match('/^sitemap-[a-z]{2,5}-\d+-\d{14}\.xml$/', $file) === 1, 404); // only names we generate: no path tricks

        return $this->file('sitemaps/'.$file, $generator);
    }

    /** First request on a fresh site: build once; concurrent requests wait on the lock instead of building twice. */
    private function ensureBuilt(SitemapGenerator $generator): void
    {
        if ($this->isBuilt()) {
            return;
        }

        Cache::lock('sitemaps:first-build', 120)->block(60, function () use ($generator): void {
            if ($this->isBuilt()) {
                return;
            }
            $generator->generate();
        });
    }

    private function isBuilt(): bool
    {
        return Storage::disk('local')->exists('sitemaps/sitemap.xml');
    }

    private function file(string $path, SitemapGenerator $generator): Response
    {
        $disk = Storage::disk('local');

        $this->ensureBuilt($generator);

        abort_unless($disk->exists($path), 404);

        return response($disk->get($path), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
