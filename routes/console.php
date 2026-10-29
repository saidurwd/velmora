<?php

declare(strict_types=1);

use App\Modules\Analytics\Jobs\PruneAnalyticsEvents;
use App\Modules\Cart\Jobs\PurgeExpiredCarts;
use App\Modules\Catalog\Jobs\PublishScheduledProducts;
use App\Modules\Catalog\Jobs\ReconcileListing;
use App\Modules\Cms\Jobs\GenerateSitemaps;
use App\Modules\IdentityAccess\Jobs\PurgeExpiredOtpChallenges;
use App\Modules\Inventory\Jobs\ExpireReservationsJob;
use App\Modules\Inventory\Jobs\VerifyStockLedger;
use App\Modules\Platform\Jobs\DispatchOutboxMessages;
use App\Modules\Search\Jobs\PruneSearchQueries;
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
Schedule::job(new ReconcileListing)->everyMinute()->name('catalog:reconcile-listing')->withoutOverlapping();
Schedule::job(new PruneSearchQueries)->dailyAt('03:10')->name('search:prune-queries');
Schedule::job(new GenerateSitemaps)->dailyAt('04:00')->name('seo:sitemaps');
Schedule::job(new PruneAnalyticsEvents)->dailyAt('03:20')->name('analytics:prune');
Schedule::job(new PurgeExpiredCarts)->dailyAt('03:40')->name('cart:purge-expired');
Schedule::job(new PublishScheduledProducts)->everyMinute()->name('catalog:publish-scheduled')->withoutOverlapping();
Schedule::job(new ExpireReservationsJob)->everyMinute()->name('inventory:expire-reservations')->withoutOverlapping();
Schedule::job(new VerifyStockLedger)->dailyAt('02:30')->name('inventory:verify-ledger');
