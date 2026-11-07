<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Actions\BuildReportExport;
use Illuminate\Console\Command;

/** Builds one waiting report export now, the way the queue worker would (for operators whose queue was down). `--start-at` holds the start until that moment, so tests can release many at once. */
final class BuildExport extends Command
{
    protected $signature = 'reports:build-export {id : the export\'s number} {--start-at= : wait until this Unix time (with decimals) before starting}';

    protected $description = 'Build a waiting report export now';

    public function handle(BuildReportExport $build): int
    {
        $startAt = $this->option('start-at');
        if (is_string($startAt) && $startAt !== '') {
            usleep((int) max(0, ((float) $startAt - microtime(true)) * 1_000_000));
        }
        $build->handle((int) $this->argument('id'));
        $this->line(json_encode(['ok' => true]) ?: '{}');

        return self::SUCCESS;
    }
}
