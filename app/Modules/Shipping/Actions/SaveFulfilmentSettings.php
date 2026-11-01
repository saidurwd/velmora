<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Settings;
use App\Modules\Shipping\Exceptions\ShipmentException;

/**
 * The shop's two delivery rules that the owner may change without a developer: how many days a customer has to send goods back, and how many failed
 * deliveries a parcel gets before a person must decide. A change is audited and applies from the next event. Who may change them (`settings.manage`) is
 * decided by the caller.
 */
final class SaveFulfilmentSettings
{
    public const WINDOW_KEY = 'shipping.return_window_days';

    public const ATTEMPTS_KEY = 'shipping.max_delivery_attempts';

    public const WINDOW_MAX = 365;

    public const ATTEMPTS_MAX = 10;

    public function __construct(private readonly Settings $settings, private readonly AuditLogger $audit) {}

    /** @return array{window: int, attempts: int} */
    public function current(): array
    {
        return [
            'window' => $this->settings->int(self::WINDOW_KEY, (int) config('shipping.return_window_days')),
            'attempts' => $this->settings->int(self::ATTEMPTS_KEY, (int) config('shipping.max_delivery_attempts')),
        ];
    }

    /** @throws ShipmentException */
    public function handle(Actor $actor, int $windowDays, int $attempts): void
    {
        if ($windowDays < 1 || $windowDays > self::WINDOW_MAX) {
            throw new ShipmentException('setting_out_of_range', ['min' => 1, 'max' => self::WINDOW_MAX]);
        }
        if ($attempts < 1 || $attempts > self::ATTEMPTS_MAX) {
            throw new ShipmentException('setting_out_of_range', ['min' => 1, 'max' => self::ATTEMPTS_MAX]);
        }

        $before = $this->current();
        $this->settings->set(self::WINDOW_KEY, $windowDays);
        $this->settings->set(self::ATTEMPTS_KEY, $attempts);
        $this->audit->record('shipping.settings_changed', null, $before, ['window' => $windowDays, 'attempts' => $attempts], $actor);
    }
}
