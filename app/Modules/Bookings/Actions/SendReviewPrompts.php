<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Services\BookingMessenger;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/** After a booking is completed, asks once how it went (with a link to the service's page; the review form itself comes with the engagement phase). Only recent completions, never old ones. */
final class SendReviewPrompts
{
    public function __construct(private readonly BookingMessenger $messenger, private readonly Clock $clock) {}

    /** @return int how many messages were sent now */
    public function handle(int $limit = 200): int
    {
        $since = $this->clock->now()->subDays((int) config('bookings.review_prompt_days'));
        $sent = 0;
        foreach (DB::table('service_bookings')->where('status', 'completed')->whereNotNull('order_item_id')->where('completed_at', '>=', $since)->orderBy('completed_at')->limit($limit)->get() as $booking) {   // index (status, ends_at)
            $sent += $this->messenger->send($booking, 'review');
        }

        return $sent;
    }
}
