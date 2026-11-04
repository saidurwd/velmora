<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use App\Modules\Bookings\Mail\BookingMail;
use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Orders\Contracts\ServiceOrderLines;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Support\LocaleFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/**
 * Sends one kind of message about one booking, by SMS and (when the order has an address) by email, in the language the order was placed in. Each message is
 * CLAIMED first with a database UNIQUE (booking, kind, channel, start time), so a retry, two workers or two scheduler runs can never send it twice; a failure
 * is recorded and tried again on a later run, at most `bookings.notice_max_attempts` times in all. Nothing about the message or the phone number is stored or logged.
 */
final class BookingMessenger
{
    public function __construct(
        private readonly ServiceOrderLines $lines, private readonly OrderDirectory $orders, private readonly PurchasableVariants $variants, private readonly SmsGateway $sms,
        private readonly LocaleFormatter $format, private readonly Clock $clock,
    ) {}

    /** @return int how many messages were sent now */
    public function send(\stdClass $booking, string $kind): int
    {
        $line = $booking->order_item_id === null ? null : $this->lines->lineFor((int) $booking->order_item_id);
        $contact = $line === null ? null : ($this->orders->contacts([$line->orderId])[$line->orderId] ?? null);
        if ($line === null || $contact === null) {
            return 0;
        }

        $sent = 0;
        foreach (array_filter(['sms' => true, 'email' => $contact['email'] !== null]) as $channel => $_) {
            if (! $this->claim((int) $booking->id, $kind, $channel, (string) $booking->starts_at)) {
                continue;
            }
            try {
                $this->deliver($booking, $kind, $channel, $line->variantId, $contact);
                $this->mark((int) $booking->id, $kind, $channel, (string) $booking->starts_at, 'sent', null);
                $sent++;
            } catch (Throwable $e) {
                $this->fail((int) $booking->id, $kind, $channel, (string) $booking->starts_at, $e);
            }
        }

        return $sent;
    }

    /** @param  array{public_id: string, number: string, email: string|null, phone: string, locale: string, user_id: int|null}  $contact */
    private function deliver(\stdClass $booking, string $kind, string $channel, int $variantId, array $contact): void
    {
        $previous = App::getLocale();
        App::setLocale($contact['locale']);
        try {
            $variant = $this->variants->details([$variantId], $contact['locale'])[$variantId] ?? null;
            $service = $variant->productName ?? '';
            $when = $this->format->dateTime(CarbonImmutable::parse((string) $booking->starts_at, 'UTC'));
            [$location, $meeting] = $this->location((int) $booking->package_id);
            $where = __('bookings::notify.where_'.$location).($location === 'online' && $meeting !== null ? ' — '.$meeting : '');   // an online service: where to join
            $link = match (true) {
                $kind === 'review' && $variant !== null => route('storefront.product', ['locale' => $contact['locale'], 'slug' => $variant->productSlug]),
                $kind !== 'review' => $this->manageLink($booking, $contact),   // where to see, move or cancel it
                default => null,
            };
            $params = ['shop' => (string) config('app.name'), 'service' => $service, 'when' => $when, 'where' => $where, 'link' => (string) $link];

            if ($channel === 'sms') {
                $result = $this->sms->send(PhoneNumber::parse($contact['phone']), __('bookings::notify.sms_'.$kind, $params), ['purpose' => 'booking_'.$kind]);
                if (! $result->accepted) {
                    throw new RuntimeException('sms_not_accepted');
                }
            } else {
                Mail::to((string) $contact['email'])->send(new BookingMail(__('bookings::notify.subject_'.$kind, $params), __('bookings::notify.heading_'.$kind, $params), __('bookings::notify.body_'.$kind, $params), $link, $contact['locale'], __('bookings::notify.link_'.$kind)));
            }
        } finally {
            App::setLocale($previous);
        }
    }

    /** @param  array{public_id: string, number: string, email: string|null, phone: string, locale: string, user_id: int|null}  $contact */
    private function manageLink(\stdClass $booking, array $contact): string
    {
        if ($contact['user_id'] !== null) {
            return route('bookings.mine.show', ['locale' => $contact['locale'], 'booking' => $booking->public_id]);
        }

        // a guest has no account: a link we sign (it expires), then a code sent to the order's phone
        return URL::temporarySignedRoute('bookings.guest', now()->addDays((int) config('orders.confirmation_link_days')), ['locale' => $contact['locale'], 'order' => $contact['public_id']]);
    }

    /** @return array{0: string, 1: string|null} where it happens, and (for an online service) the address to join */
    private function location(int $packageId): array
    {
        $s = DB::table('service_packages as p')->join('service_definitions as s', 's.id', '=', 'p.service_id')->where('p.id', $packageId)->first(['s.location_type', 's.meeting_url']);   // primary keys

        return [(string) ($s->location_type ?? ''), $s->meeting_url ?? null];
    }

    private function claim(int $bookingId, string $kind, string $channel, string $startsAt): bool
    {
        $now = $this->clock->now();
        $key = ['booking_id' => $bookingId, 'kind' => $kind, 'channel' => $channel, 'for_starts_at' => $startsAt];
        if (DB::table('booking_notices')->insertOrIgnore($key + ['status' => 'claimed', 'attempts' => 1, 'created_at' => $now, 'updated_at' => $now]) === 1) {
            return true;
        }

        // a failed message is tried again, and one a worker died holding is taken over after ten minutes: always by ONE conditional update
        return DB::table('booking_notices')->where($key)->where('attempts', '<', $this->settingsMax())
            ->where(fn ($q) => $q->where('status', 'failed')->orWhere(fn ($w) => $w->where('status', 'claimed')->where('updated_at', '<', $now->subMinutes(10))))
            ->update(['status' => 'claimed', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]) === 1;   // index: the unique key
    }

    private function mark(int $bookingId, string $kind, string $channel, string $startsAt, string $status, ?string $error): void
    {
        DB::table('booking_notices')->where(['booking_id' => $bookingId, 'kind' => $kind, 'channel' => $channel, 'for_starts_at' => $startsAt])->where('status', 'claimed')
            ->update(['status' => $status, 'last_error' => $error, 'updated_at' => $this->clock->now()]);
    }

    private function fail(int $bookingId, string $kind, string $channel, string $startsAt, Throwable $e): void
    {
        $row = DB::table('booking_notices')->where(['booking_id' => $bookingId, 'kind' => $kind, 'channel' => $channel, 'for_starts_at' => $startsAt])->first(['attempts']);
        $this->mark($bookingId, $kind, $channel, $startsAt, $row !== null && (int) $row->attempts >= $this->settingsMax() ? 'gave_up' : 'failed', mb_substr($e::class, 0, 120));
        Log::warning('bookings.notice_failed', ['booking_id' => $bookingId, 'kind' => $kind, 'channel' => $channel, 'error' => $e::class]);
    }

    private function settingsMax(): int
    {
        return max(1, (int) config('bookings.notice_max_attempts'));
    }
}
