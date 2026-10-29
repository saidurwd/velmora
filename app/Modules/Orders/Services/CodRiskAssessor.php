<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\DTOs\RiskAssessment;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The cash-on-delivery abuse rules. Refuse: a blocked phone or address; too many open cash orders from one phone.
 * Ask for proof: a large cash order needs an SMS code. Look closer: many orders from one phone in a day, or one address used by
 * many phones, are placed but held for a person to review. All limits are settings the owner can change.
 */
final class CodRiskAssessor
{
    public function __construct(private readonly Settings $settings, private readonly Clock $clock) {}

    public function requiresPhoneConfirmation(int $grandTotalMinor, string $paymentMethod): bool
    {
        $limit = $this->settings->int('fraud.high_value_cod_minor', 1_000_000);

        return $this->enabled() && $paymentMethod === 'cod' && $limit > 0 && $grandTotalMinor > $limit;
    }

    public function assess(string $phoneE164, ?string $addressFingerprint, int $grandTotalMinor, string $paymentMethod, bool $phoneConfirmed): RiskAssessment
    {
        $now = $this->clock->now();
        $stamp = fn ($t): string => $t->format('Y-m-d H:i:s.u');

        // an explicit decision by staff: always on
        $blocked = DB::table('order_blocklist')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $stamp($now)))
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('kind', 'phone')->where('value_hash', AddressFingerprint::phone($phoneE164)))
                ->when($addressFingerprint !== null, fn ($q) => $q->orWhere(fn ($w) => $w->where('kind', 'address')->where('value_hash', $addressFingerprint))))
            ->exists();
        if ($blocked) {
            return new RiskAssessment(blocked: true);
        }

        if (! $this->enabled()) {
            return new RiskAssessment;
        }

        $maxOpen = $this->settings->int('fraud.max_open_cod_per_phone', 3);
        if ($paymentMethod === 'cod' && $maxOpen > 0) {
            $open = DB::table('orders')->where('contact_phone', $phoneE164)->where('payment_method_code', 'cod')
                ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Confirmed->value, OrderStatus::Processing->value])->count();
            if ($open >= $maxOpen) {
                return new RiskAssessment(tooManyOpenCod: true, maxOpenCod: $maxOpen);
            }
        }

        $flags = [];

        $perDay = $this->settings->int('fraud.velocity_orders_per_day', 5);
        if ($perDay > 0) {
            $recent = DB::table('orders')->where('contact_phone', $phoneE164)->where('placed_at', '>=', $stamp($now->subDay()))->count();
            if ($recent >= $perDay) {
                $flags[] = ['rule' => 'velocity_phone', 'score' => 40, 'details' => ['orders_24h' => $recent, 'limit' => $perDay]];
            }
        }

        $maxPhones = $this->settings->int('fraud.address_max_phones', 3);
        if ($maxPhones > 0 && $addressFingerprint !== null) {
            $others = DB::table('order_addresses as a')->join('orders as o', 'o.id', '=', 'a.order_id')
                ->where('a.fingerprint', $addressFingerprint)->where('a.created_at', '>=', $stamp($now->subDays(30)))->where('o.contact_phone', '!=', $phoneE164)
                ->distinct()->count('o.contact_phone');
            if ($others + 1 >= $maxPhones) {
                $flags[] = ['rule' => 'address_many_phones', 'score' => 30, 'details' => ['phones_30d' => $others + 1, 'limit' => $maxPhones]];
            }
        }

        return new RiskAssessment(needsPhoneConfirmation: ! $phoneConfirmed && $this->requiresPhoneConfirmation($grandTotalMinor, $paymentMethod), flags: $flags);
    }

    private function enabled(): bool
    {
        return $this->settings->bool('fraud.enabled', true);
    }
}
