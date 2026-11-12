<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Support\Api\PostmanCollection;
use Illuminate\Console\Command;

/** Writes the Postman collection and environment from docs/api/openapi.json (run after `scramble:export`). */
final class ApiCollectionCommand extends Command
{
    protected $signature = 'api:collection';

    protected $description = 'Make the Postman / Bruno collection and environment from the API document';

    public function handle(PostmanCollection $collection): int
    {
        $doc = (array) json_decode((string) file_get_contents(base_path('docs/api/openapi.json')), true);
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        file_put_contents(base_path('docs/api/velmora.postman_collection.json'), json_encode($collection->collection($doc), $flags)."\n");
        file_put_contents(base_path('docs/api/velmora.postman_environment.json'), json_encode($collection->environment(), $flags)."\n");
        $this->info('Collection and environment written to docs/api/.');

        return self::SUCCESS;
    }
}
