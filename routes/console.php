<?php

declare(strict_types=1);

use App\Modules\Catalog\Jobs\PublishScheduledProducts;
use App\Modules\IdentityAccess\Jobs\PurgeExpiredOtpChallenges;
use App\Modules\Inventory\Jobs\ExpireReservationsJob;
use App\Modules\Inventory\Jobs\VerifyStockLedger;
use App\Modules\Platform\Jobs\DispatchOutboxMessages;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new DispatchOutboxMessages)->everyMinute()->name('outbox:dispatch')->withoutOverlapping();
Schedule::call(fn () => Cache::put('scheduler:heartbeat', now()->toIso8601String(), 600))->everyMinute()->name('scheduler:heartbeat');
Schedule::job(new PurgeExpiredOtpChallenges)->dailyAt('03:15')->name('otp:purge');
Schedule::job(new PublishScheduledProducts)->everyMinute()->name('catalog:publish-scheduled')->withoutOverlapping();
Schedule::job(new ExpireReservationsJob)->everyMinute()->name('inventory:expire-reservations')->withoutOverlapping();
Schedule::job(new VerifyStockLedger)->dailyAt('02:30')->name('inventory:verify-ledger');
