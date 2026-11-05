<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Services\SavableVariants;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/**
 * A signed-in customer saves a variant for later. Saving it twice is the same as once (the unique key decides). The list is capped; the cap is soft by the few
 * requests that can arrive at the same instant, which the write throttle keeps small. The customer comes from the session, never from the request.
 */
final class AddToWishlist
{
    public function __construct(private readonly SavableVariants $variants, private readonly Clock $clock) {}

    /**
     * @return bool true when newly saved, false when it was already on the list
     *
     * @throws EngagementException not_found | service | wishlist_full
     */
    public function handle(int $userId, string $variantPublicId, string $locale): bool
    {
        $variant = $this->variants->resolve($variantPublicId, $locale);

        if (DB::table('wishlist_items')->where('user_id', $userId)->where('variant_id', $variant->id)->exists()) {   // unique (user_id, variant_id)
            return false;
        }
        if (DB::table('wishlist_items')->where('user_id', $userId)->count() >= ($max = (int) config('engagement.wishlist_max', 200))) {
            throw new EngagementException('wishlist_full', ['max' => $max]);
        }

        return DB::table('wishlist_items')->insertOrIgnore(['user_id' => $userId, 'variant_id' => $variant->id, 'created_at' => $this->clock->now()]) === 1;
    }
}
