<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Services\Seo\SitemapGenerator;
use Illuminate\Console\Command;

class GenerateSitemapsCommand extends Command
{
    protected $signature = 'seo:sitemaps';

    protected $description = 'Regenerate the XML sitemaps now (they are also rebuilt nightly)';

    public function handle(SitemapGenerator $generator): int
    {
        $this->info('Wrote '.$generator->generate().' sitemap addresses.');

        return self::SUCCESS;
    }
}
