<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\DatabasePaymentMethodCatalog;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Settings;

/**
 * The owner's switches for taking money: the online-payments freeze, the refund second-approver limit, and which payment methods are offered.
 * Each change is audited and takes effect for the very next checkout. Who may change what is decided by the caller (freeze: `payments.reconcile`;
 * the rest: `settings.manage`).
 */
final class ManagePaymentSettings
{
    public const REFUND_LIMIT_MAX = 100_000_000; // ৳1,000,000.00 in paisa

    public const LIMIT_KEY = 'payments.refund_second_approver_minor';

    public function __construct(private readonly Settings $settings, private readonly AuditLogger $audit, private readonly GatewayRegistry $gateways) {}

    public function frozen(): bool
    {
        return $this->settings->bool(DatabasePaymentMethodCatalog::FREEZE_SETTING, false);
    }

    public function refundLimit(): int
    {
        return $this->settings->int(self::LIMIT_KEY, (int) config('payments.refund_second_approver_minor'));
    }

    public function freeze(Actor $actor, bool $frozen): void
    {
        $before = $this->frozen();
        $this->settings->set(DatabasePaymentMethodCatalog::FREEZE_SETTING, $frozen);
        $this->audit->record('payments.freeze', null, ['frozen' => $before], ['frozen' => $frozen], $actor);
    }

    /** @throws PaymentException */
    public function setRefundLimit(Actor $actor, int $minor): void
    {
        if ($minor < 0 || $minor > self::REFUND_LIMIT_MAX) {
            throw new PaymentException('setting_out_of_range', ['min' => 0, 'max' => self::REFUND_LIMIT_MAX / 100]);
        }
        $before = $this->refundLimit();
        $this->settings->set(self::LIMIT_KEY, $minor);
        $this->audit->record('payments.refund_limit', null, ['minor' => $before], ['minor' => $minor], $actor);
    }

    /** @throws PaymentException */
    public function setMethodEnabled(Actor $actor, string $code, bool $enabled): void
    {
        $method = PaymentMethod::query()->where('code', $code)->first() ?? throw new PaymentException('method_not_found');
        if ($enabled && $method->type !== 'cod' && ! $this->gateways->has($method->provider)) {
            throw new PaymentException('provider_not_configured'); // nothing to take the money with yet
        }
        if ($method->is_enabled === $enabled) {
            return;
        }
        $method->forceFill(['is_enabled' => $enabled])->save();
        $this->audit->record('payments.method', $method, ['enabled' => ! $enabled], ['enabled' => $enabled, 'code' => $code], $actor);
    }
}
