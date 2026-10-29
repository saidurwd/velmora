<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\AddressFingerprint;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Support\Facades\DB;

/**
 * Stops a phone and/or an address from ordering again, after a problem order. Only hashes are stored. Existing orders are
 * untouched (cancel them separately). Blocking the same value twice just switches the entry back on.
 */
final class BlockContact
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @return int how many entries are now active for this order */
    public function handle(Actor $actor, Order $order, bool $phone, bool $address, ?string $reason): int
    {
        $reason = $reason === null || trim($reason) === '' ? null : mb_substr(trim(BanglaText::input($reason)), 0, 255);
        $entries = [];

        if ($phone) {
            $entries[] = ['phone', AddressFingerprint::phone($order->contact_phone), PhoneNumber::tryParse($order->contact_phone)?->masked() ?? '****'];
        }
        if ($address) {
            $row = $order->addresses()->where('type', 'shipping')->first();
            if ($row !== null && $row->fingerprint !== null) {
                $entries[] = ['address', $row->fingerprint, mb_substr($row->locationLine('en'), 0, 80)];
            }
        }

        $now = $this->clock->now();
        foreach ($entries as [$kind, $hash, $hint]) {
            // one entry per value: blocking again switches it back on (and updates the reason) instead of adding a second
            DB::table('order_blocklist')->upsert(
                [['kind' => $kind, 'value_hash' => $hash, 'hint' => $hint, 'reason' => $reason, 'created_by' => $actor->id, 'is_active' => true, 'expires_at' => null, 'created_at' => $now, 'updated_at' => $now]],
                ['kind', 'value_hash'], ['reason', 'created_by', 'is_active', 'expires_at', 'updated_at'],
            );
            $this->audit->record('order.blocklist.added', $order, [], ['kind' => $kind, 'hint' => $hint, 'reason' => $reason], $actor);
        }

        return count($entries);
    }
}
