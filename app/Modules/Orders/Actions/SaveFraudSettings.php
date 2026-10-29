<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Settings;

/** The cash-on-delivery abuse limits (see CodRiskAssessor). Takes effect for the very next checkout. */
final class SaveFraudSettings
{
    /** key => [min, max] for the whole-number settings */
    public const LIMITS = [
        'fraud.max_open_cod_per_phone' => [0, 50],
        'fraud.velocity_orders_per_day' => [0, 200],
        'fraud.address_max_phones' => [0, 50],
    ];

    public function __construct(private readonly Settings $settings, private readonly AuditLogger $audit) {}

    /** @return array<string, bool|int> the values in force, as the form shows them */
    public function current(): array
    {
        return [
            'fraud.enabled' => $this->settings->bool('fraud.enabled', true),
            'fraud.max_open_cod_per_phone' => $this->settings->int('fraud.max_open_cod_per_phone', 3),
            'fraud.high_value_cod_minor' => $this->settings->int('fraud.high_value_cod_minor', 1_000_000),
            'fraud.velocity_orders_per_day' => $this->settings->int('fraud.velocity_orders_per_day', 5),
            'fraud.address_max_phones' => $this->settings->int('fraud.address_max_phones', 3),
        ];
    }

    /**
     * @param  array<string, bool|int>  $values  same keys as current()
     *
     * @throws OrderException
     */
    public function handle(Actor $actor, array $values): void
    {
        foreach (self::LIMITS as $key => [$min, $max]) {
            $v = $values[$key] ?? null;
            if (! is_int($v) || $v < $min || $v > $max) {
                throw new OrderException('setting_out_of_range', ['setting' => $key, 'min' => $min, 'max' => $max]);
            }
        }
        $high = $values['fraud.high_value_cod_minor'] ?? null;
        if (! is_int($high) || $high < 0 || $high > 10_000_000_00) {
            throw new OrderException('setting_out_of_range', ['setting' => 'fraud.high_value_cod_minor', 'min' => 0, 'max' => 1_000_000_000]);
        }

        $before = $this->current();
        $this->settings->set('fraud.enabled', (bool) ($values['fraud.enabled'] ?? false));
        foreach (array_keys(self::LIMITS) as $key) {
            $this->settings->set($key, $values[$key]);
        }
        $this->settings->set('fraud.high_value_cod_minor', $high);

        $this->audit->record('settings.fraud.updated', 'settings', $before, $this->current(), $actor, 'fraud');
    }
}
