<?php

declare(strict_types=1);

namespace App\Modules\Search\Console;

use App\Modules\Search\Services\SearchIndexer;
use Illuminate\Console\Command;

class ReindexSearchCommand extends Command
{
    protected $signature = 'search:reindex';

    protected $description = 'Rebuild the search index and vocabulary from the catalog (safe to run any time)';

    public function handle(SearchIndexer $indexer): int
    {
        $this->info('Indexed '.$indexer->rebuild().' live products.');

        return self::SUCCESS;
    }
}
