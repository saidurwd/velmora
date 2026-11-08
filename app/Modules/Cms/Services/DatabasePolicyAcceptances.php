<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Contracts\PolicyAcceptances;
use App\Modules\Cms\DTOs\PolicyNotice;
use App\Modules\Cms\Enums\PolicyKind;
use App\Modules\Cms\Exceptions\PolicyException;
use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Current" = the newest release of a kind that has taken effect. "Required" = the newest release that asked for acceptance.
 * A person is up to date for a kind when they accepted a release at least as new as the required one.
 */
final class DatabasePolicyAcceptances implements PolicyAcceptances
{
    public function __construct(private readonly Clock $clock) {}

    public function outstanding(?int $userId, string $context, ?string $paymentMethod, string $locale): array
    {
        $kinds = PolicyKind::acceptedAt($context, $paymentMethod);
        $current = $this->current($kinds);
        if ($current->isEmpty()) {
            return []; // nothing released: nothing to accept
        }

        $currentKinds = array_values(array_map('strval', $current->keys()->all()));
        $required = $this->required($currentKinds);
        $accepted = $userId === null ? collect() : DB::table('policy_acceptances as a')->join('cms_policy_releases as r', 'r.id', '=', 'a.release_id')
            ->where('a.user_id', $userId)->whereIn('r.kind', $currentKinds)->groupBy('r.kind')->selectRaw('r.kind as kind, MAX(r.id) as release_id')->pluck('release_id', 'kind');

        $open = $current->filter(fn ($release, $kind) => isset($required[$kind]) && (int) ($accepted[$kind] ?? 0) < (int) $required[$kind]);

        return $this->notices($open, $locale);
    }

    public function assertShown(?int $userId, string $context, ?string $paymentMethod, array $shownReleaseIds): void
    {
        $outstanding = array_map(fn (PolicyNotice $n) => $n->releaseId, $this->outstanding($userId, $context, $paymentMethod, (string) config('app.default_locale')));
        if ($outstanding === []) {
            return;
        }

        $shown = $this->ids($shownReleaseIds);
        sort($outstanding);
        $shown !== [] || throw PolicyException::required();
        $shown === $outstanding || throw PolicyException::changed();
    }

    public function accept(?int $userId, ?int $orderId, string $channel, array $releaseIds): void
    {
        ($userId === null) !== ($orderId === null) || throw new \InvalidArgumentException('A policy is accepted by a customer or by an order, not both and not neither.');

        $ids = $this->ids($releaseIds);
        $ids !== [] || throw PolicyException::required();

        $releases = DB::table('cms_policy_releases')->whereIn('id', $ids)->get(['id', 'kind']);
        $releases->count() === count($ids) || throw PolicyException::changed();

        $current = $this->current(array_values(array_map('strval', $releases->pluck('kind')->unique()->all())));
        foreach ($releases as $r) {
            (int) ($current[$r->kind]->id ?? 0) === (int) $r->id || throw PolicyException::changed(); // a newer release came out while they were reading
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        DB::table('policy_acceptances')->insertOrIgnore(array_map(fn (int $id) => [
            'release_id' => $id, 'user_id' => $userId, 'order_id' => $orderId, 'channel' => $channel, 'accepted_at' => $now,
        ], $ids));
    }

    public function carryOrderToUser(int $orderId, int $userId): void
    {
        DB::insert("INSERT IGNORE INTO policy_acceptances (release_id, user_id, order_id, channel, accepted_at) SELECT release_id, ?, NULL, 'signup', accepted_at FROM policy_acceptances WHERE order_id = ?", [$userId, $orderId]);
    }

    /**
     * @param  list<string>  $kinds
     * @return Collection<string, \stdClass>
     */
    private function current(array $kinds): Collection
    {
        return DB::table('cms_policy_releases')->whereIn('kind', $kinds)->where('effective_at', '<=', $this->clock->now()->format('Y-m-d H:i:s.u'))
            ->orderByDesc('id')->get()->unique('kind')->keyBy('kind');
    }

    /**
     * @param  list<string>  $kinds
     * @return Collection<string, int>
     */
    private function required(array $kinds): Collection
    {
        return DB::table('cms_policy_releases')->whereIn('kind', $kinds)->where('acceptance_required', true)->where('effective_at', '<=', $this->clock->now()->format('Y-m-d H:i:s.u'))
            ->groupBy('kind')->selectRaw('kind, MAX(id) as id')->pluck('id', 'kind');
    }

    /**
     * @param  Collection<string, \stdClass>  $releases
     * @return list<PolicyNotice>
     */
    private function notices(Collection $releases, string $locale): array
    {
        if ($releases->isEmpty()) {
            return [];
        }

        $default = (string) config('app.default_locale');
        $titles = DB::table('cms_page_version_translations')->whereIn('version_id', $releases->pluck('version_id')->all())->whereIn('locale', [$locale, $default])->get()->groupBy('version_id');
        $numbers = DB::table('cms_page_versions')->whereIn('id', $releases->pluck('version_id')->all())->pluck('number', 'id');
        $live = DB::table('cms_page_translations as t')->join('cms_pages as p', 'p.id', '=', 't.page_id')->whereIn('t.page_id', $releases->pluck('page_id')->all())
            ->where('p.status', 'published')->whereIn('t.locale', [$locale, $default])->get(['t.page_id', 't.locale', 't.slug'])->groupBy('page_id');

        $order = array_flip(PolicyKind::values());
        $out = [];
        foreach ($releases->sortBy(fn ($r) => $order[$r->kind] ?? 99) as $r) {
            $rows = $titles->get($r->version_id, collect());
            $row = $rows->firstWhere('locale', $locale) ?? $rows->firstWhere('locale', $default);
            $slugs = $live->get($r->page_id, collect());
            $slug = ($slugs->firstWhere('locale', $locale) ?? $slugs->firstWhere('locale', $default))?->slug;
            $slugLocale = $slugs->firstWhere('locale', $locale) !== null ? $locale : $default;

            $out[] = new PolicyNotice($r->kind, (int) $r->id, (int) ($numbers[$r->version_id] ?? 0), (string) ($row->title ?? ''), $slug === null ? null : url("/{$slugLocale}/pages/".rawurlencode((string) $slug)), CarbonImmutable::parse((string) $r->effective_at, 'UTC'));
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        $clean = array_values(array_unique(array_map('intval', $ids)));
        sort($clean);

        return $clean;
    }
}
