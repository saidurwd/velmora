<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Actions;

use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * What staff may do for a customer without giving them a new purchase: more time, more downloads, or a fresh count. Every change is written to the audit
 * trail with the old and the new value. A revoked right is never touched. Who may do this (`digital.manage`) is decided by the caller.
 */
final class AdjustEntitlement
{
    public const MAX_DAYS = 3650;

    public const MAX_DOWNLOADS = 1000;

    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** Adds days to the time allowed, counting from today if it has already run out. @throws DigitalException */
    public function extend(Actor $actor, string $entitlementPublicId, int $days): Entitlement
    {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new DigitalException('days_invalid', ['max' => self::MAX_DAYS]);
        }

        return $this->change($actor, $entitlementPublicId, 'extended', function (Entitlement $right) use ($days): array {
            if ($right->expires_at === null) {
                throw new DigitalException('no_expiry_to_extend');   // it never runs out: there is nothing to extend
            }
            $from = $right->expires_at->greaterThan($this->clock->now()) ? $right->expires_at : $this->clock->now();

            return ['expires_at' => $from->addDays($days)];
        });
    }

    /** Gives the customer more downloads on top of their limit. @throws DigitalException */
    public function addDownloads(Actor $actor, string $entitlementPublicId, int $extra): Entitlement
    {
        if ($extra < 1 || $extra > self::MAX_DOWNLOADS) {
            throw new DigitalException('downloads_invalid', ['max' => self::MAX_DOWNLOADS]);
        }

        return $this->change($actor, $entitlementPublicId, 'downloads_added', function (Entitlement $right) use ($extra): array {
            if ($right->download_limit === null) {
                throw new DigitalException('already_unlimited');
            }

            return ['download_limit' => $right->download_limit + $extra];
        });
    }

    /** Starts the count of downloads again from none. @throws DigitalException */
    public function resetDownloads(Actor $actor, string $entitlementPublicId): Entitlement
    {
        return $this->change($actor, $entitlementPublicId, 'downloads_reset', fn (): array => ['downloads_used' => 0]);
    }

    /** @param  callable(Entitlement): array<string, mixed>  $decide */
    private function change(Actor $actor, string $publicId, string $what, callable $decide): Entitlement
    {
        return Transactions::run(function () use ($actor, $publicId, $what, $decide): Entitlement {
            $right = Entitlement::query()->where('public_id', $publicId)->lockForUpdate()->first() ?? throw new DigitalException('entitlement_not_found');
            if ($right->status->value !== 'active') {
                throw new DigitalException('entitlement_already_revoked');
            }
            $changes = $decide($right);
            $before = $right->only(array_keys($changes));
            DB::table('entitlements')->where('id', $right->id)->update($changes + ['updated_at' => $this->clock->now()]);
            $right->refresh();
            $this->audit->record('digital.entitlement_'.$what, 'entitlement', $before, $right->only(array_keys($changes)) + ['order_id' => $right->order_id], $actor, $right->id);

            return $right;
        });
    }
}
