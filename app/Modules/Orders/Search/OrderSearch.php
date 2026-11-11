<?php

declare(strict_types=1);

namespace App\Modules\Orders\Search;

use App\Modules\Platform\Contracts\SearchProvider;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\DTOs\SearchHit;
use App\Modules\Platform\Exceptions\InvalidPhoneNumber;
use App\Modules\Platform\Support\Fmt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Orders by their number (a prefix) or by the exact phone number on them. The phone is shown only to people who may see personal data. */
final class OrderSearch implements SearchProvider
{
    public function kind(): string
    {
        return 'orders::search.kind';
    }

    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function search(string $term, int $limit, bool $seePii): array
    {
        $query = DB::table('orders')->orderByDesc('id')->limit($limit);
        $number = strtoupper(trim($term));

        if (preg_match('/^[A-Z0-9-]{3,20}$/', $number) === 1 && preg_match('/\d{6,}/', str_replace('-', '', $number)) !== 1) {
            $query->where('order_number', 'like', addcslashes($number, '%_\\').'%');   // unique index order_number: a prefix range
        } else {
            try {
                $phone = PhoneNumber::parse($term)->e164();
            } catch (InvalidPhoneNumber) {
                $query->where('order_number', 'like', addcslashes($number, '%_\\').'%');   // not a phone number: try it as a number prefix
                $phone = null;
            }
            $phone === null || $query->where('contact_phone', $phone);   // index contact_phone: an exact match
        }

        return array_values($query->get(['public_id', 'order_number', 'status', 'payment_status', 'contact_name', 'contact_phone', 'placed_at'])->map(function ($o) use ($seePii): SearchHit {
            $who = $seePii ? (string) $o->contact_phone : PhoneNumber::parse((string) $o->contact_phone)->masked();

            return new SearchHit((string) $o->order_number, __('orders::search.detail', ['status' => __('orders::orders.status.'.$o->status), 'payment' => __('orders::orders.payment.'.$o->payment_status), 'who' => $who, 'date' => Fmt::date(CarbonImmutable::parse((string) $o->placed_at))]), route('admin.orders.show', (string) $o->public_id));
        })->all());
    }
}
