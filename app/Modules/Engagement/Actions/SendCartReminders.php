<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Cart\Contracts\AbandonedCarts;
use App\Modules\Cart\DTOs\AbandonedCart;
use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Customers\DTOs\CustomerProfile;
use App\Modules\IdentityAccess\Contracts\AccountLocales;
use App\Modules\Notifications\Contracts\MarketingConsent;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reminds signed-in customers who left items in their cart. This is marketing, so it is strict:
 *  - off unless the shop turned it on (engagement.cart_recovery.enabled);
 *  - only carts with lines whose newest change is between "idle_hours" and "max_age_days" old (an emptied cart, such as one that became an order, is never listed);
 *  - only a customer who agreed to that channel (email first, otherwise text); the sender still holds the message through their quiet hours;
 *  - one reminder per cart episode (a cart that changes again is a new one) and at least "min_gap_days" between two reminders to the same customer;
 *  - at most "per_run" reminders in a run.
 * The reminder is claimed by inserting its row (unique per customer and episode) in the same transaction that records the message, so two workers cannot both send it
 * and a message that could not be recorded leaves nothing claimed.
 */
final class SendCartReminders
{
    private const MAX_PAGES = 10;

    public function __construct(
        private readonly AbandonedCarts $carts,
        private readonly CustomerProfiles $profiles,
        private readonly AccountLocales $locales,
        private readonly MarketingConsent $consent,
        private readonly NotificationSender $sender,
        private readonly Clock $clock,
    ) {}

    /** @return int how many customers were reminded */
    public function handle(): int
    {
        if (! (bool) config('engagement.cart_recovery.enabled')) {
            return 0;
        }

        $now = $this->clock->now();
        $idleSince = $now->subHours(max(1, (int) config('engagement.cart_recovery.idle_hours')));
        $notBefore = $now->subDays(max(1, (int) config('engagement.cart_recovery.max_age_days')));
        $cap = max(1, (int) config('engagement.cart_recovery.per_run'));

        $size = max(1, (int) config('engagement.cart_recovery.page'));
        $sent = 0;
        for ($page = 0; $page < self::MAX_PAGES && $sent < $cap; $page++) {
            $candidates = $this->carts->idle($idleSince, $notBefore, $size, $page * $size);
            if ($candidates === []) {
                break;
            }

            $ids = array_map(fn (AbandonedCart $c): int => $c->userId, $candidates);
            $contacts = $this->profiles->findManyByUserIds($ids);
            $locales = $this->locales->forUsers($ids);
            foreach ($candidates as $cart) {
                if ($sent >= $cap) {
                    break;
                }
                try {
                    $sent += $this->remind($cart, $contacts[$cart->userId] ?? null, $locales[$cart->userId] ?? (string) config('app.fallback_locale')) ? 1 : 0;
                } catch (Throwable $e) {
                    Log::warning('engagement.cart_reminder_not_recorded', ['user' => $cart->userId, 'error' => $e::class]);   // nothing was claimed; the next run tries again
                }
            }
            if (count($candidates) < $size) {
                break;
            }
        }

        return $sent;
    }

    /** Reminds one customer about one cart episode. False when they are not to be reminded (no consent, no address, reminded recently, or another worker was first). */
    public function remind(AbandonedCart $cart, ?CustomerProfile $contact, string $locale): bool
    {
        if ($contact === null) {
            return false;
        }
        $channel = ($contact->email ?? '') !== '' && $this->consent->allows($cart->userId, 'email') ? 'email'
            : (($contact->phone ?? '') !== '' && $this->consent->allows($cart->userId, 'sms') ? 'sms' : null);
        if ($channel === null) {
            return false;
        }

        $now = $this->clock->now();
        $gap = max(1, (int) config('engagement.cart_recovery.min_gap_days'));
        if (DB::table('cart_recovery_messages')->where('user_id', $cart->userId)->where('created_at', '>', $now->subDays($gap))->exists()) {   // index (user_id, created_at)
            return false;
        }

        $locale = in_array($locale, (array) config('app.supported_locales'), true) ? $locale : (string) config('app.fallback_locale');

        return Transactions::run(function () use ($cart, $contact, $channel, $locale, $now): bool {
            try {
                DB::table('cart_recovery_messages')->insert(['user_id' => $cart->userId, 'cart_stamp' => $cart->stamp(), 'channel' => $channel, 'created_at' => $now]);   // unique (user_id, cart_stamp)
            } catch (UniqueConstraintViolationException) {
                return false;   // this episode was already reminded (or another worker is doing it)
            }

            $this->sender->send(
                "engagement.cart_reminder.{$channel}",
                new Recipient($channel === 'sms' ? $contact->phone : null, $channel === 'email' ? $contact->email : null, $locale, $cart->userId),
                ['shop' => (string) config('app.name'), 'link' => route('cart.show', ['locale' => $locale]), 'preferences' => route('account.preferences', ['locale' => $locale])],
                'cart-reminder|'.$cart->userId.'|'.$cart->stamp(),
                'cart-reminder',
            );

            return true;
        });
    }
}
