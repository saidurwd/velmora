<?php

declare(strict_types=1);

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Actions\CancelOwnOrder;
use App\Modules\Orders\Actions\LinkOrderToCustomer;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Console\Command;
use Throwable;

/**
 * Concurrency-test entry point: each OS process (its own database connection) claims ONE guest order for a customer, or cancels ONE order as
 * its owner, and prints a JSON line. Never available in production.
 */
class AccountProbeCommand extends Command
{
    protected $signature = 'orders:account-probe {action : link|cancel} {--order=} {--user-id=} {--start-at=}';

    protected $description = 'Claim or cancel one order as a customer and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(LinkOrderToCustomer $link, CancelOwnOrder $cancel): int
    {
        if (app()->environment('production')) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        $userId = (int) $this->option('user-id');
        if ($at = (float) $this->option('start-at')) {
            while (microtime(true) < $at) {
                usleep(200);
            }
        }

        try {
            if ($this->argument('action') === 'link') {
                $link->handle(Actor::guest('probe'), (string) $this->option('order'), $userId);
            } else {
                $cancel->handle(new Actor(ActorType::Customer, $userId, null), (string) $this->option('order'), 'concurrency probe');
            }
            $this->line(json_encode(['ok' => true, 'user' => $userId], JSON_THROW_ON_ERROR));
        } catch (OrderException $e) {
            $this->line(json_encode(['ok' => false, 'reason' => $e->reason, 'user' => $userId], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'reason' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
