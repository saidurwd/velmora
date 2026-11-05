<?php

declare(strict_types=1);

namespace App\Modules\Orders\Privacy;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Privacy\Contracts\PersonalDataEraser;
use App\Modules\Privacy\Contracts\PersonalDataExporter;
use Illuminate\Support\Facades\DB;

/**
 * A customer's orders: copied into their data export; and on erasure the name, phone, email and delivery address on each order are replaced by fixed placeholders
 * while amounts, items, tax, payments and dates stay (the financial record). The database only allows exactly these placeholders (see the migration).
 */
final class OrderPersonalData implements PersonalDataEraser, PersonalDataExporter
{
    public const NAME = 'Deleted customer';

    public const PHONE = 'erased';

    /** Orders in these states are still being handled; the customer cannot be erased until they are over. */
    private const OPEN = ['pending', 'confirmed', 'processing'];

    public function __construct(private readonly Clock $clock) {}

    public function section(): string
    {
        return 'orders';
    }

    public function export(int $userId): array
    {
        $orders = DB::table('orders')->where('user_id', $userId)->orderBy('id')->get();   // index user_id
        $ids = $orders->pluck('id')->all();
        $items = DB::table('order_items')->whereIn('order_id', $ids)->orderBy('id')->get()->groupBy('order_id');   // index order_id
        $addresses = DB::table('order_addresses')->whereIn('order_id', $ids)->get()->groupBy('order_id');

        return $orders->map(fn ($o) => [
            'order_number' => $o->order_number, 'placed_at' => $o->placed_at, 'status' => $o->status, 'payment_status' => $o->payment_status, 'currency' => $o->currency,
            'subtotal_minor' => (int) $o->subtotal_minor, 'discount_minor' => (int) $o->discount_minor, 'shipping_minor' => (int) $o->shipping_minor, 'cod_fee_minor' => (int) $o->cod_fee_minor, 'tax_minor' => (int) $o->tax_minor, 'total_minor' => (int) $o->grand_total_minor,
            'payment_method' => $o->payment_method_code, 'contact' => ['name' => $o->contact_name, 'phone' => $o->contact_phone, 'email' => $o->contact_email],
            'items' => ($items[$o->id] ?? collect())->map(fn ($i) => ['name' => $i->name_snapshot, 'sku' => $i->sku_snapshot, 'quantity' => (int) $i->quantity, 'unit_price_minor' => (int) $i->unit_price_minor, 'line_total_minor' => (int) $i->line_total_minor])->values()->all(),
            'addresses' => ($addresses[$o->id] ?? collect())->map(fn ($a) => ['type' => $a->type, 'recipient' => $a->recipient_name, 'phone' => $a->phone, 'area' => $a->area, 'address' => $a->address_line, 'landmark' => $a->landmark, 'postal_code' => $a->postal_code])->values()->all(),
        ])->values()->all();
    }

    public function blockers(int $userId): array
    {
        return DB::table('orders')->where('user_id', $userId)->whereIn('status', self::OPEN)->exists() ? ['open'] : [];   // index user_id
    }

    public function erase(int $userId): void
    {
        $now = $this->clock->now();
        $ids = DB::table('orders')->where('user_id', $userId)->pluck('id')->all();
        if ($ids === []) {
            return;
        }
        // The delivery address is not part of the money record.
        DB::table('order_addresses')->whereIn('order_id', $ids)->update([
            'recipient_name' => self::NAME, 'phone' => self::PHONE, 'fingerprint' => null, 'area' => '', 'address_line' => '', 'landmark' => null, 'delivery_note' => null, 'postal_code' => null, 'updated_at' => $now,
        ]);
        DB::table('orders')->whereIn('id', $ids)->whereNull('anonymised_at')->update(['contact_name' => self::NAME, 'contact_phone' => self::PHONE, 'contact_email' => null, 'anonymised_at' => $now]);
    }
}
