<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\AuditPeople;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\PersonRef;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the audit trail for the security and activity screens (activity log, security events, login history, "recent sign-ins").
 * Newest first, paged by id, every filter bound. The indexes it relies on: (action, created_at), (actor_id, created_at), (subject_type, subject_id,
 * created_at) and (created_at). Names come in one batch per page from AuditPeople, never one query per row.
 */
final class AuditFeed
{
    public const PAGE = 50;

    private const COLUMNS = ['id', 'created_at', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'after', 'ip', 'user_agent'];

    public function __construct(private readonly AuditPeople $people, private readonly Clock $clock) {}

    /**
     * @param  array{actions?: list<string>, except?: list<string>, prefixes?: list<string>, actor_types?: list<string>, from?: string|null, to?: string|null, person?: PersonRef|null, actor_id?: int|null, among?: list<PersonRef>|null, not_among?: list<PersonRef>|null}  $f
     */
    public function builder(array $f): Builder
    {
        $q = DB::table('audit_events');
        $zone = (string) config('app.display_timezone');

        if (($f['actions'] ?? []) !== []) {
            $q->whereIn('action', $f['actions']);
        }
        if (($f['except'] ?? []) !== []) {
            $q->whereNotIn('action', $f['except']);
        }
        if (($f['prefixes'] ?? []) !== []) {
            $q->where(function (Builder $w) use ($f): void {
                foreach ($f['prefixes'] as $prefix) {
                    $w->orWhere('action', 'like', addcslashes($prefix, '%_\\').'.%');   // uses the (action, created_at) index as a range
                }
            });
        }
        if (($f['actor_types'] ?? []) !== []) {
            $q->whereIn('actor_type', $f['actor_types']);
        }
        if (($f['actor_id'] ?? null) !== null) {
            $q->where('actor_id', $f['actor_id']);
        }
        if (($person = $f['person'] ?? null) instanceof PersonRef) {
            $q->where(fn (Builder $w) => $this->aboutPeople($w, [$person]));
        }
        if (is_array($f['among'] ?? null)) {   // done by or about any of these people (e.g. all staff)
            $f['among'] === [] ? $q->whereRaw('1 = 0') : $q->where(fn (Builder $w) => $this->aboutPeople($w, $f['among']));
        }
        if (is_array($f['not_among'] ?? null) && $f['not_among'] !== []) {   // neither done by nor about any of them (e.g. customers: not staff)
            $this->notAboutPeople($q, $f['not_among']);
        }
        if (($f['from'] ?? '') !== '') {
            $q->where('created_at', '>=', CarbonImmutable::parse($f['from'].' 00:00:00', $zone)->utc()->format('Y-m-d H:i:s.u'));
        }
        if (($f['to'] ?? '') !== '') {
            $q->where('created_at', '<', CarbonImmutable::parse($f['to'].' 00:00:00', $zone)->addDay()->utc()->format('Y-m-d H:i:s.u'));   // the whole last day
        }

        return $q;
    }

    /**
     * One page, newest first; $before is the id of the last row of the previous page.
     *
     * @param  array<string, mixed>  $f
     * @return array{rows: Collection<int, \stdClass>, next: int|null}
     */
    public function page(array $f, ?int $before, int $size = self::PAGE): array
    {
        $q = $this->builder($f)->orderByDesc('id')->limit($size + 1);
        $before === null || $q->where('id', '<', $before);

        $rows = $q->get(self::COLUMNS);
        $more = $rows->count() > $size;
        $rows = $rows->take($size)->values();

        return ['rows' => $rows, 'next' => $more ? (int) $rows->last()?->id : null];
    }

    /**
     * The people on a page: for each row, who did it (actor) and whose account it was about (subject), when known. Two lookups for the whole page.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return array<int, array{actor: PersonRef|null, subject: PersonRef|null}> keyed by row id
     */
    public function people(Collection $rows): array
    {
        $types = $this->people->accountSubjectTypes();
        $ids = $rows->pluck('actor_id')->filter()->map(fn ($v) => (int) $v);
        $publicIds = [];
        foreach ($rows as $r) {
            if ($r->subject_id !== null && in_array($r->subject_type, $types, true)) {
                ctype_digit((string) $r->subject_id) ? $ids->push((int) $r->subject_id) : $publicIds[] = (string) $r->subject_id;
            }
        }
        $byId = $this->people->byIds(array_values($ids->unique()->all()));
        $byPublic = $this->people->byPublicIds(array_values(array_unique($publicIds)));

        $out = [];
        foreach ($rows as $r) {
            $subject = null;
            if ($r->subject_id !== null && in_array($r->subject_type, $types, true)) {
                $subject = ctype_digit((string) $r->subject_id) ? ($byId[(int) $r->subject_id] ?? null) : ($byPublic[(string) $r->subject_id] ?? null);
            }
            $out[(int) $r->id] = ['actor' => $r->actor_id === null ? null : ($byId[(int) $r->actor_id] ?? null), 'subject' => $subject];
        }

        return $out;
    }

    /**
     * How many of each action happened in the last $hours.
     *
     * @param  list<string>  $actions
     * @return array<string, int>
     */
    public function counts(array $actions, int $hours = 24): array
    {
        $since = $this->clock->now()->subHours($hours)->format('Y-m-d H:i:s.u');
        $found = DB::table('audit_events')->whereIn('action', $actions)->where('created_at', '>=', $since)   // index (action, created_at)
            ->groupBy('action')->pluck(DB::raw('count(*)'), 'action')->map(fn ($n) => (int) $n)->all();

        return array_replace(array_fill_keys($actions, 0), $found);
    }

    /**
     * The network addresses with the most of these actions in the last $hours, masked (a.b.c.x), with how many and the latest time.
     *
     * @param  list<string>  $actions
     * @return list<array{ip: string, count: int, last: string}>
     */
    public function topSources(array $actions, int $hours = 24, int $limit = 5): array
    {
        $since = $this->clock->now()->subHours($hours)->format('Y-m-d H:i:s.u');
        $rows = DB::table('audit_events')->whereIn('action', $actions)->where('created_at', '>=', $since)->whereNotNull('ip')   // index (action, created_at)
            ->groupBy('ip')->orderByDesc(DB::raw('count(*)'))->limit(50)->get(['ip', DB::raw('count(*) as n'), DB::raw('max(created_at) as last')]);

        // grouped again by the masked address, so two addresses that look the same on screen are one line
        $merged = [];
        foreach ($rows as $r) {
            $masked = AuditLogQuery::maskIp((string) $r->ip);
            $merged[$masked] ??= ['ip' => $masked, 'count' => 0, 'last' => (string) $r->last];
            $merged[$masked]['count'] += (int) $r->n;
            $merged[$masked]['last'] = max($merged[$masked]['last'], (string) $r->last);
        }
        usort($merged, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return array_slice($merged, 0, $limit);
    }

    /**
     * Rows done by one of these people, or about one of their accounts (a failed password tried on an account has no actor, only the account as subject).
     *
     * @param  list<PersonRef>  $people
     */
    private function aboutPeople(Builder $w, array $people): void
    {
        $types = $this->people->accountSubjectTypes();
        $w->whereIn('actor_id', array_map(fn (PersonRef $p) => $p->id, $people));
        if ($types !== []) {
            $keys = [];
            foreach ($people as $p) {
                $keys[] = $p->publicId;
                $keys[] = (string) $p->id;
            }
            $w->orWhere(fn (Builder $s) => $s->whereIn('subject_type', $types)->whereIn('subject_id', $keys));
        }
    }

    /**
     * The opposite of aboutPeople, written out so a row with no actor or no subject still counts as "not them" (NOT (NULL IN ...) is unknown in SQL, not true).
     *
     * @param  list<PersonRef>  $people
     */
    private function notAboutPeople(Builder $q, array $people): void
    {
        $ids = array_map(fn (PersonRef $p) => $p->id, $people);
        $q->where(fn (Builder $w) => $w->whereNull('actor_id')->orWhereNotIn('actor_id', $ids));
        $types = $this->people->accountSubjectTypes();
        if ($types !== []) {
            $keys = [];
            foreach ($people as $p) {
                $keys[] = $p->publicId;
                $keys[] = (string) $p->id;
            }
            $q->where(fn (Builder $w) => $w->whereNull('subject_type')->orWhereNotIn('subject_type', $types)->orWhereNull('subject_id')->orWhereNotIn('subject_id', $keys));
        }
    }
}
