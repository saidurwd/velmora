<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Listeners;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\DigitalProducts\Events\DigitalAssetVersionPublished;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Tells the people who own a product that a newer file is ready: one email each, once per version (a database UNIQUE decides, so a retry or two workers
 * never send twice), to the email the order was placed with. The first version is not news. A revoked right is not told. Owners with no email simply see the
 * new version in their library. If a send fails, its record is taken back and the event is retried later, without repeating those already told.
 */
final class NotifyOwnersOfNewVersion
{
    public function __construct(private readonly OrderDirectory $orders, private readonly VariantDirectory $variants, private readonly Clock $clock, private readonly NotificationSender $sender) {}

    public function handle(DigitalAssetVersionPublished $event): void
    {
        if ($event->version < 2) {
            return;
        }

        $product = $this->variants->findMany([$event->variantId])[$event->variantId]->productName ?? '';
        DB::table('entitlements')->where('variant_id', $event->variantId)->where('status', 'active')->orderBy('id')->chunkById(100, function ($rights) use ($event, $product): void {   // index (variant_id, status)
            $contacts = $this->orders->contacts(array_values(array_unique(array_map('intval', $rights->pluck('order_id')->all()))));
            foreach ($rights as $right) {
                $contact = $contacts[(int) $right->order_id] ?? null;
                if ($contact === null || $contact['email'] === null) {
                    continue;
                }
                $told = DB::table('entitlement_version_notices')->insertOrIgnore(['entitlement_id' => $right->id, 'version_id' => $event->versionId, 'created_at' => $this->clock->now()]);
                if ($told === 0) {
                    continue;   // already told
                }
                try {
                    $this->sender->send('digital.new_version.email', new Recipient(email: $contact['email'], locale: $contact['locale'], userId: $contact['user_id']), ['product' => $product, 'version' => $event->version, 'link' => $this->link($contact)], 'version-'.$event->versionId.'-right-'.$right->id, 'order:'.$contact['public_id']);
                } catch (Throwable $e) {
                    DB::table('entitlement_version_notices')->where('entitlement_id', $right->id)->where('version_id', $event->versionId)->delete();   // not told after all: a retry will try again

                    throw $e;
                }
            }
        });
    }

    /** @param  array{public_id: string, user_id: int|null, locale: string}  $contact */
    private function link(array $contact): string
    {
        if ($contact['user_id'] !== null) {
            return route('digital.library', ['locale' => $contact['locale']]);
        }

        return Route::has('digital.guest.library') ? URL::temporarySignedRoute('digital.guest.library', now()->addDays((int) config('orders.confirmation_link_days')), ['locale' => $contact['locale'], 'order' => $contact['public_id']]) : url('/');
    }
}
