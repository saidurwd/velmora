<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Contracts\IntegrityCheck;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\IntegrityResult;
use App\Modules\Platform\Exceptions\OpsException;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\IntegrityRegistry;
use App\Modules\Platform\Support\Redactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the data-integrity checks (all, or one) and keeps the last result of each. A check that fails to run is recorded as "error" and does not stop
 * the others. Nothing is ever repaired: this only reports. A person who runs it by hand is audited; the nightly run logs what it finds.
 */
final class RunIntegrityChecks
{
    public function __construct(private readonly IntegrityRegistry $registry, private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /**
     * @return array<string, array{status: string, problems: int}> code => outcome
     *
     * @throws OpsException
     */
    public function handle(?Actor $actor = null, ?string $only = null): array
    {
        $checks = $this->registry->checks();
        if ($only !== null) {
            $checks = array_values(array_filter($checks, fn (IntegrityCheck $c) => $c->code() === $only));
            $checks !== [] || throw OpsException::unknownCheck();
        }

        $out = [];
        foreach ($checks as $check) {
            $started = hrtime(true);
            try {
                $result = $check->run();
                $status = $result->isOk() ? 'ok' : 'problem';
            } catch (Throwable $e) {
                $result = new IntegrityResult(0, [], mb_substr(Redactor::string((string) strtok($e->getMessage(), "\n")), 0, 255));
                $status = 'error';
            }

            $this->store($check->code(), $status, $result, (int) round((hrtime(true) - $started) / 1_000_000));
            $status === 'ok' || Log::error('ops.integrity_'.$status, ['check' => $check->code(), 'problems' => $result->problems, 'samples' => $result->samples, 'note' => $result->note]);
            $out[$check->code()] = ['status' => $status, 'problems' => $result->problems];
        }

        $actor === null || $this->audit->record('ops.integrity_run', 'integrity', [], ['checks' => array_keys($out), 'outcome' => array_map(fn (array $o) => $o['status'], $out)], $actor);

        return $out;
    }

    private function store(string $code, string $status, IntegrityResult $r, int $ms): void
    {
        DB::table('integrity_check_runs')->updateOrInsert(['code' => $code], [
            'status' => $status, 'problems' => $r->problems, 'samples' => $r->samples === [] ? null : json_encode(array_slice($r->samples, 0, (int) config('ops.integrity.sample')), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'note' => $r->note === null ? null : mb_substr($r->note, 0, 255), 'duration_ms' => $ms, 'ran_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
        ]);
    }
}
