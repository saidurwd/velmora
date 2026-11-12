<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Support\Api\OpenApiCompatibility;
use Illuminate\Console\Command;

/** Checks the API document against the frozen baseline: any change an app already out there could trip on fails (exit 1). `--freeze` makes the current document the new baseline (a release decision, see docs/api/VERSIONING_AND_DEPRECATION.md). */
final class ApiCompatCommand extends Command
{
    protected $signature = 'api:compat {--freeze : Replace the baseline with the current document}';

    protected $description = 'List what the API document changed that an existing app could trip on';

    public function handle(OpenApiCompatibility $compat): int
    {
        $current = base_path('docs/api/openapi.json');
        $baseline = base_path('docs/api/baseline/openapi.v1.json');

        if ($this->option('freeze')) {
            if (! is_dir(dirname($baseline))) {
                mkdir(dirname($baseline), 0o755, true);
            }
            copy($current, $baseline);
            $this->info('The baseline is now the current document.');

            return self::SUCCESS;
        }

        $breaking = $compat->breaking((array) json_decode((string) file_get_contents($baseline), true), (array) json_decode((string) file_get_contents($current), true));
        foreach ($breaking as $line) {
            $this->error($line);
        }
        $breaking === [] ? $this->info('Nothing an existing app could trip on.') : $this->line(count($breaking).' breaking change(s). Make it additive, or follow the deprecation policy.');

        return $breaking === [] ? self::SUCCESS : self::FAILURE;
    }
}
