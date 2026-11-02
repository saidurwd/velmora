<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Actions;

use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Shows a customer the keys given to their right. Who may see them (the owner, or a guest in a code session for that order) is decided by the caller. Keys
 * stay visible after downloads run out or time passes (they were bought), but never once the right is revoked. Each showing is written to the audit trail
 * as a count and the key ids; the keys themselves are never written anywhere.
 */
final class RevealLicenseKeys
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<string>
     *
     * @throws DigitalException download_revoked | keys_waiting
     */
    public function handle(Entitlement $entitlement, ?int $userId): array
    {
        if ($entitlement->status->value !== 'active') {
            throw new DigitalException('download_revoked');
        }

        $rows = DB::table('license_keys')->where('entitlement_id', $entitlement->id)->where('status', 'allocated')->orderBy('id')->get(['id', 'key_encrypted']);
        if ($rows->isEmpty()) {
            throw new DigitalException('keys_waiting');
        }

        DB::table('entitlements')->where('id', $entitlement->id)->whereNull('first_used_at')->update(['first_used_at' => now()]);   // the first look at the keys counts as first use (set once)
        $this->audit->record('digital.license_keys_shown', 'entitlement', [], ['entitlement_id' => $entitlement->id, 'key_ids' => $rows->pluck('id')->map(fn ($v): int => (int) $v)->all(), 'count' => $rows->count()], new Actor($userId === null ? ActorType::Guest : ActorType::Customer, $userId), $entitlement->id);

        return array_values($rows->map(fn ($r): string => Crypt::decryptString((string) $r->key_encrypted))->all());
    }
}
