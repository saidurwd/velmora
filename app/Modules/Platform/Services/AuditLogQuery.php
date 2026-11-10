<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Reads the append-only audit log for the viewer and the export. Filters are values already checked by the request; every one is bound, none is concatenated. */
final class AuditLogQuery
{
    public const PAGE = 50;

    /** The most rows one export holds (config velmora.audit.export_cap). */
    public static function exportCap(): int
    {
        return max(1, (int) config('velmora.audit.export_cap', 50_000));
    }

    public const LIST_COLUMNS = ['id', 'created_at', 'actor_type', 'actor_id', 'actor_label', 'action', 'subject_type', 'subject_id', 'request_id', 'ip'];

    /**
     * @param  array{from?: string|null, to?: string|null, actor?: string|null, action?: string|null, subject_type?: string|null, subject_id?: string|null, request_id?: string|null}  $f
     */
    public function builder(array $f): Builder
    {
        $q = DB::table('audit_events');
        $zone = (string) config('app.display_timezone');

        if (($f['from'] ?? '') !== '') {
            $q->where('created_at', '>=', CarbonImmutable::parse($f['from'].' 00:00:00', $zone)->utc()->format('Y-m-d H:i:s.u'));
        }
        if (($f['to'] ?? '') !== '') {
            $q->where('created_at', '<', CarbonImmutable::parse($f['to'].' 00:00:00', $zone)->addDay()->utc()->format('Y-m-d H:i:s.u'));   // the whole last day
        }
        if (($actor = trim((string) ($f['actor'] ?? ''))) !== '') {
            ctype_digit($actor) ? $q->where('actor_id', (int) $actor) : $q->where('actor_label', 'like', self::prefix($actor));
        }
        if (($action = trim((string) ($f['action'] ?? ''))) !== '') {
            $q->where('action', 'like', self::prefix($action));
        }
        if (($f['subject_type'] ?? '') !== '') {
            $q->where('subject_type', $f['subject_type']);
        }
        if (($f['subject_id'] ?? '') !== '') {
            $q->where('subject_id', $f['subject_id']);
        }
        if (($f['request_id'] ?? '') !== '') {
            $q->where('request_id', $f['request_id']);
        }

        return $q;
    }

    /**
     * One page, newest first. $before is the id of the last row of the previous page.
     *
     * @param  array<string, mixed>  $f
     * @return array{rows: Collection<int, \stdClass>, next: int|null}
     */
    public function page(array $f, ?int $before): array
    {
        $q = $this->builder($f)->orderByDesc('id')->limit(self::PAGE + 1);
        $before === null || $q->where('id', '<', $before);

        $rows = $q->get(self::LIST_COLUMNS);
        $more = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE)->values();

        return ['rows' => $rows, 'next' => $more ? (int) $rows->last()?->id : null];
    }

    /** A person's address is shown only roughly: the last part is hidden (IPv4 a.b.c.x, IPv6 the first four groups). */
    public static function maskIp(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return '';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return $parts[0].'.'.$parts[1].'.'.$parts[2].'.x';
        }

        return implode(':', array_slice(explode(':', $ip), 0, 4)).':…';
    }

    /** A "starts with" pattern: the user's % and _ are plain characters. */
    private static function prefix(string $text): string
    {
        return addcslashes($text, '%_\\').'%';
    }
}
