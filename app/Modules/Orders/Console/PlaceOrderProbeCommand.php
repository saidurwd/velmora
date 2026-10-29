<?php

declare(strict_types=1);

namespace App\Modules\Orders\Console;

use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Customers\Contracts\AddressChecker;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\DTOs\PlaceOrderCommand;
use App\Modules\Orders\Exceptions\CheckoutException;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Console\Command;
use Throwable;

/** Concurrency-test entry point: each OS process places ONE order for a guest cart and prints a JSON line. Never in production. */
class PlaceOrderProbeCommand extends Command
{
    protected $signature = 'orders:place-probe {guest} {token} {--division=0} {--district=0} {--upazila=0} {--expected=0} {--phone=01712345678} {--start-at=}';

    protected $description = 'Place an order for a guest cart once and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(PlaceOrder $place, AddressChecker $addresses): int
    {
        if (app()->environment('production')) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        if ($at = (float) $this->option('start-at')) {
            while (microtime(true) < $at) {
                usleep(200);
            }
        }

        try {
            $address = $addresses->check(new AddressInput('Test Buyer', (string) $this->option('phone'), (int) $this->option('division'), (int) $this->option('district'), (int) $this->option('upazila'), 'House 1, Road 1'));
            $order = $place->handle(new PlaceOrderCommand(
                Actor::guest('probe'), CartOwner::guest((string) $this->argument('guest')), null, 'Test Buyer', (string) $this->option('phone'), null, $address, 'standard', 'cod',
                (int) $this->option('expected'), (string) $this->argument('token'), 'en',
            ));
            $this->line(json_encode(['ok' => true, 'order' => $order->order_number, 'id' => $order->id], JSON_THROW_ON_ERROR));
        } catch (CheckoutException $e) {
            $this->line(json_encode(['ok' => false, 'reason' => $e->reason], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'reason' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
