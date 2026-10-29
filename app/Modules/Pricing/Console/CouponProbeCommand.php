<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Console;

use App\Modules\Pricing\Contracts\CouponRedeemer;
use App\Modules\Pricing\DTOs\AppliedDiscount;
use App\Modules\Pricing\DTOs\DiscountOutcome;
use App\Modules\Pricing\Exceptions\CouponException;
use Illuminate\Console\Command;
use Throwable;

/** Concurrency-test entry point: each OS process redeems ONE coupon for ONE (fake) order and prints a JSON line. Never in production. */
class CouponProbeCommand extends Command
{
    protected $signature = 'pricing:coupon-probe {code} {--order=1} {--redeemer=} {--amount=100} {--start-at=}';

    protected $description = 'Redeem a coupon once and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(CouponRedeemer $redeemer): int
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
            $redeemer->redeem(new DiscountOutcome(applied: [new AppliedDiscount('coupon', (string) $this->argument('code'), (string) $this->argument('code'), (int) $this->option('amount'))]), (int) $this->option('order'), $this->option('redeemer') ?: null);
            $this->line(json_encode(['ok' => true], JSON_THROW_ON_ERROR));
        } catch (CouponException $e) {
            $this->line(json_encode(['ok' => false, 'reason' => $e->reason], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'reason' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
