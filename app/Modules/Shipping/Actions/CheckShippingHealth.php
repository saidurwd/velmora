<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Shipping\Services\ShippingHealth;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/** Every fifteen minutes: write an ERROR log line for each delivery signal that is breached (once an hour per signal, so the log is not flooded). Monitoring alerts on the log. */
final class CheckShippingHealth
{
    public function __construct(private readonly ShippingHealth $health, private readonly Cache $cache) {}

    /** @return list<string> the signals logged now */
    public function handle(): array
    {
        $logged = [];
        foreach ($this->health->breached() as $name => $signal) {
            if ($this->cache->add('shipping.alert.'.$name, true, now()->addHour())) {
                Log::error('shipping.alert', ['signal' => $name, 'value' => $signal['value'], 'threshold' => $signal['threshold']]);
                $logged[] = $name;
            }
        }

        return $logged;
    }
}
