<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Catalog\DTOs\PurchasableVariant;
use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Customers\DTOs\CustomerProfile;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every few minutes: for each variant that has waiting alerts and is back in stock (and still on sale), tell the people who asked, oldest first, and no more than twice as
 * many as there are units (at most 100 per variant per run, so a single unit does not send hundreds of messages); the rest wait for the next run while stock lasts.
 * Each alert is claimed with one conditional update inside the same transaction that records the message, so two workers cannot both tell the same person and a message
 * that could not be recorded leaves the alert waiting. One message per person: email when we have one, otherwise a text message.
 */
final class NotifyBackInStock
{
    public const PER_UNIT = 2;

    public const PER_VARIANT_PER_RUN = 100;

    private const VARIANTS_PER_RUN = 200;

    /** @var array<string, PurchasableVariant|null> */
    private array $seen = [];

    public function __construct(
        private readonly Availability $availability,
        private readonly PurchasableVariants $variants,
        private readonly CustomerProfiles $profiles,
        private readonly NotificationSender $sender,
        private readonly Clock $clock,
    ) {}

    /** @return int how many people were told */
    public function handle(): int
    {
        $variantIds = array_values(DB::table('stock_alerts')->where('status', 'pending')->distinct()->orderBy('variant_id')->limit(self::VARIANTS_PER_RUN)->pluck('variant_id')   // index (status, variant_id, id)
            ->map(fn ($v): int => (int) $v)->all());
        if ($variantIds === []) {
            return 0;
        }

        $available = $this->availability->forVariants($variantIds);
        $told = 0;
        foreach ($variantIds as $variantId) {
            $units = $available[$variantId] ?? 0;
            if ($units < 1) {
                continue;
            }
            $alerts = DB::table('stock_alerts')->where('status', 'pending')->where('variant_id', $variantId)->orderBy('id')->limit(min(self::PER_UNIT * $units, self::PER_VARIANT_PER_RUN))->get();   // index (status, variant_id, id)
            $contacts = $this->profiles->findManyByUserIds(array_values(array_unique(array_map(fn ($a): int => (int) $a->user_id, $alerts->all()))));
            foreach ($alerts as $alert) {
                $variant = $this->variant($variantId, (string) $alert->locale);
                if ($variant === null || ! $variant->isPurchasable) {
                    continue;   // not on sale (any more): keep waiting
                }
                try {
                    $told += $this->tell($alert, $variant, $contacts[(int) $alert->user_id] ?? null) ? 1 : 0;
                } catch (Throwable $e) {
                    Log::warning('engagement.stock_alert_not_recorded', ['alert' => $alert->id, 'error' => $e::class]);   // it stays waiting; the next run tries again
                }
            }
        }

        return $told;
    }

    private function variant(int $variantId, string $locale): ?PurchasableVariant
    {
        return $this->seen["{$variantId}|{$locale}"] ??= $this->variants->details([$variantId], $locale)[$variantId] ?? null;
    }

    /**
     * Tells one person: claims the alert and records the message in one transaction. False when another worker claimed it first, or when there is no one to tell.
     * Public so the claim can be tested with an alert row that has gone stale.
     */
    public function tell(\stdClass $alert, PurchasableVariant $variant, ?CustomerProfile $contact): bool
    {
        return Transactions::run(function () use ($alert, $variant, $contact): bool {
            $now = $this->clock->now();
            if (DB::table('stock_alerts')->where('id', $alert->id)->where('status', 'pending')->update(['status' => 'notified', 'notified_at' => $now, 'updated_at' => $now]) !== 1) {
                return false;   // another worker was first
            }
            $channel = ($contact->email ?? '') !== '' ? 'email' : (($contact->phone ?? '') !== '' ? 'sms' : null);
            if ($channel === null || $contact === null) {
                return false;   // nobody to tell (an erased or addressless account): the alert is closed
            }

            $this->sender->send(
                "engagement.back_in_stock.{$channel}",
                new Recipient($channel === 'sms' ? $contact->phone : null, $channel === 'email' ? $contact->email : null, (string) $alert->locale, (int) $alert->user_id),
                ['shop' => (string) config('app.name'), 'product' => trim($variant->productName.($variant->optionsLabel !== null ? ' ('.$variant->optionsLabel.')' : '')), 'link' => route('storefront.product', ['locale' => $alert->locale, 'slug' => $variant->productSlug])],
                'stock-alert|'.$alert->id.'|'.$now->format('YmdHisu'),
                'variant:'.$variant->publicId,
            );

            return true;
        });
    }
}
