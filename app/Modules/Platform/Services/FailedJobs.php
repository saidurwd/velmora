<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Support\Redactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Failed jobs as the staff screen shows them: what failed, when and why in one line. The payload (which may hold personal data) is never shown. */
final class FailedJobs
{
    public const PAGE = 30;

    /**
     * @return array{rows: list<array{id: int, queue: string, class: string, failed_at: CarbonImmutable, error: string, retryable: bool}>, next: int|null}
     */
    public function page(?int $before): array
    {
        $q = DB::table('failed_jobs')->orderByDesc('id')->limit(self::PAGE + 1);
        $before === null || $q->where('id', '<', $before);
        $rows = $q->get(['id', 'queue', 'payload', 'exception', 'failed_at']);

        $more = $rows->count() > self::PAGE;
        $out = [];
        foreach ($rows->take(self::PAGE) as $r) {
            $class = self::classOf((string) $r->payload);
            $out[] = ['id' => (int) $r->id, 'queue' => (string) $r->queue, 'class' => $class, 'failed_at' => CarbonImmutable::parse((string) $r->failed_at, 'UTC'), 'error' => self::firstLine((string) $r->exception), 'retryable' => self::isRetryable($class)];
        }

        return ['rows' => $out, 'next' => $more && $out !== [] ? $out[array_key_last($out)]['id'] : null];
    }

    public function find(int $id): ?\stdClass
    {
        return DB::table('failed_jobs')->where('id', $id)->first();
    }

    public static function classOf(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $name = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : 'unknown';
    }

    public static function isRetryable(string $class): bool
    {
        return in_array($class, (array) config('ops.retryable_jobs'), true);
    }

    /** The first line of the error, with secrets and phone numbers removed, cut short. */
    public static function firstLine(string $exception): string
    {
        return mb_substr(Redactor::string((string) strtok($exception, "\n")), 0, 200);
    }
}
