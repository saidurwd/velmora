<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Services\PaymentHealth;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/** Every five minutes: write an ERROR log line for each payment signal that is breached (once an hour per signal, so the log is not flooded). The log is what monitoring alerts on. */
final class CheckPaymentHealth
{
    public function __construct(private readonly PaymentHealth $health, private readonly Cache $cache) {}

    /** @return list<string> the signals logged now */
    public function handle(): array
    {
        $logged = [];
        foreach ($this->health->snapshot() as $name => $signal) {
            if (! $signal['breached']) {
                continue;
            }
            if ($this->cache->add('payments.alert.'.$name, true, now()->addHour())) {
                Log::error('payments.alert', ['signal' => $name, 'value' => $signal['value'], 'threshold' => $signal['threshold']]);
                $logged[] = $name;
            }
        }

        return $logged;
    }
}
