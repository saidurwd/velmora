<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Short-lived cache for anonymous storefront reads (home rails, listings, facets, footer links).
 *
 * Every key carries a "storefront version". Anything that changes what the public sees (catalog edits, price or stock
 * changes caught by the reconcile job, CMS content, branding) calls bump(), so the next request misses and rebuilds:
 * invalidation is instant and needs no key lists. The TTL is the backstop for changes nobody bumps for (≤ MAX_AGE seconds).
 */
final class StorefrontCache
{
    public const MAX_AGE = 120;

    private const VERSION_KEY = 'storefront:version';

    public function __construct(private readonly Repository $cache) {}

    public function version(): int
    {
        return (int) $this->cache->get(self::VERSION_KEY, 1);
    }

    public function bump(): void
    {
        // add() makes sure the counter exists; increment() is atomic, so concurrent bumps never lose one
        $this->cache->add(self::VERSION_KEY, 1, 86400 * 30);
        $this->cache->increment(self::VERSION_KEY);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    public function remember(string $key, Closure $build, int $ttl = self::MAX_AGE): mixed
    {
        return $this->cache->remember('sf:'.$this->version().':'.app()->getLocale().':'.$key, $ttl, $build);
    }
}
