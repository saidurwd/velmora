<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Services\AddressFingerprint;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Exceptions\InvalidPhoneNumber;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Support\Facades\DB;

/** Add a phone number to the block list by hand, and switch entries off or on. (Addresses are blocked from an order.) */
final class ManageBlocklist
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @throws OrderException */
    public function addPhone(Actor $actor, string $typedPhone, ?string $reason): void
    {
        try {
            $phone = PhoneNumber::parse(BanglaText::digitsToAscii($typedPhone));
        } catch (InvalidPhoneNumber) {
            throw new OrderException('phone_invalid');
        }

        $reason = $reason === null || trim($reason) === '' ? null : mb_substr(trim(BanglaText::input($reason)), 0, 255);
        $now = $this->clock->now();
        DB::table('order_blocklist')->upsert(
            [['kind' => 'phone', 'value_hash' => AddressFingerprint::phone($phone->e164()), 'hint' => $phone->masked(), 'reason' => $reason, 'created_by' => $actor->id, 'is_active' => true, 'expires_at' => null, 'created_at' => $now, 'updated_at' => $now]],
            ['kind', 'value_hash'], ['reason', 'created_by', 'is_active', 'expires_at', 'updated_at'],
        );

        $this->audit->record('order.blocklist.added', 'blocklist', [], ['kind' => 'phone', 'hint' => $phone->masked(), 'reason' => $reason], $actor);
    }

    /** @throws OrderException */
    public function setActive(Actor $actor, int $entryId, bool $active): void
    {
        $entry = DB::table('order_blocklist')->where('id', $entryId)->first() ?? throw new OrderException('blocklist_entry_missing');

        DB::table('order_blocklist')->where('id', $entryId)->update(['is_active' => $active, 'expires_at' => $active ? null : $entry->expires_at, 'updated_at' => $this->clock->now()]);

        $this->audit->record($active ? 'order.blocklist.enabled' : 'order.blocklist.disabled', 'blocklist', ['is_active' => (bool) $entry->is_active], ['kind' => $entry->kind, 'hint' => $entry->hint, 'is_active' => $active], $actor, $entryId);
    }
}
