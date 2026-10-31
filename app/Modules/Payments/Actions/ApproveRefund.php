<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Refund;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Settings;
use App\Modules\Platform\Support\Transactions;

/**
 * Approval is a separate step from asking, so no refund is paid on one person's say-so above a limit: a refund bigger than
 * `payments.refund_second_approver_minor` (0 = no limit) must be approved by someone other than the person who asked for it.
 */
final class ApproveRefund
{
    public function __construct(private readonly Settings $settings, private readonly AuditLogger $audit) {}

    /** @throws PaymentException */
    public function handle(Actor $actor, string $refundPublicId): Refund
    {
        $refund = Transactions::run(function () use ($actor, $refundPublicId): Refund {
            $refund = Refund::query()->where('public_id', $refundPublicId)->lockForUpdate()->first() ?? throw new PaymentException('refund_not_found');
            if ($refund->status !== RefundStatus::Requested) {
                throw new PaymentException('refund_not_pending');
            }

            $limit = $this->settings->int('payments.refund_second_approver_minor', (int) config('payments.refund_second_approver_minor'));
            if ($limit > 0 && $refund->amount_minor > $limit && $actor->id !== null && $actor->id === $refund->requested_by) {
                throw new PaymentException('refund_needs_second_person', ['limit' => $limit]);
            }

            $refund->forceFill(['status' => RefundStatus::Approved, 'approved_by' => $actor->id])->save();

            return $refund;
        });

        $this->audit->record('refund.approved', $refund, ['status' => 'requested'], ['status' => 'approved', 'amount_minor' => $refund->amount_minor], $actor);

        return $refund;
    }
}
