<?php

declare(strict_types=1);

namespace App\Modules\Cart\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Carts nobody touched before their expiry (30 days for guests, 90 for customers) are deleted with their lines. */
class PurgeExpiredCarts implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        do {
            $deleted = DB::table('carts')->where('expires_at', '<', now('UTC'))->limit(500)->delete();
        } while ($deleted === 500);
    }
}
