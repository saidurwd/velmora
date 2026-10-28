<?php

declare(strict_types=1);

namespace App\Modules\Cms\Jobs;

use App\Modules\Cms\Services\Seo\SitemapGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateSitemaps implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(SitemapGenerator $generator): void
    {
        $generator->generate();
    }
}
