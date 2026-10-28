<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Services\Storefront\ListingProjection;
use Illuminate\Console\Command;

class ReindexListingCommand extends Command
{
    protected $signature = 'catalog:reindex';

    protected $description = 'Rebuild the storefront listing read model from the catalog (safe to run any time)';

    public function handle(ListingProjection $projection): int
    {
        $this->info('Reindexed '.$projection->rebuild().' published products.');

        return self::SUCCESS;
    }
}
