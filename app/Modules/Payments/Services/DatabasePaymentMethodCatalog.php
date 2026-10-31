<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentMethodCatalog;
use App\Modules\Payments\DTOs\PaymentMethodOption;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Platform\Support\Settings;

final class DatabasePaymentMethodCatalog implements PaymentMethodCatalog
{
    public const FREEZE_SETTING = 'payments.online_frozen';

    public function __construct(private readonly Settings $settings, private readonly GatewayRegistry $gateways) {}

    public function options(int $orderTotalMinor, array $productTypes, string $locale): array
    {
        $frozen = $this->settings->bool(self::FREEZE_SETTING, false);
        $out = [];

        foreach (PaymentMethod::query()->with('translations')->where('is_enabled', true)->orderBy('sort_order')->orderBy('id')->get() as $m) {
            $isCash = $m->type === 'cod';
            if (! $isCash && ($frozen || ! $this->gateways->has($m->provider))) {
                continue; // online payments are switched off, or no gateway is set up for this method
            }
            if (($m->min_minor !== null && $orderTotalMinor < $m->min_minor) || ($m->max_minor !== null && $orderTotalMinor > $m->max_minor)) {
                continue;
            }
            $allowed = $m->restrictions['product_types'] ?? null;
            if (is_array($allowed) && $allowed !== [] && array_diff($productTypes, $allowed) !== []) {
                continue;
            }

            $out[] = new PaymentMethodOption($m->code, $m->type, $m->provider, $m->translate('label', $locale) ?? $m->code, $m->translate('description', $locale));
        }

        return $out;
    }

    public function label(string $code, string $locale): string
    {
        $method = PaymentMethod::query()->with('translations')->where('code', $code)->first();

        return $method?->translate('label', $locale) ?? $code;
    }
}
