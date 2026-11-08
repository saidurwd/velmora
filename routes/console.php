<?php

declare(strict_types=1);

use App\Modules\Analytics\Jobs\PruneAnalyticsEvents;
use App\Modules\Bookings\Jobs\ExpireStaleHoldsJob;
use App\Modules\Bookings\Jobs\RunBookingLifecycleJob;
use App\Modules\Cart\Jobs\PurgeExpiredCarts;
use App\Modules\Catalog\Jobs\PublishScheduledProducts;
use App\Modules\Catalog\Jobs\ReconcileListing;
use App\Modules\Cms\Jobs\GenerateSitemaps;
use App\Modules\Cms\Jobs\PublishDuePagesJob;
use App\Modules\DigitalProducts\Jobs\CheckKeyStockJob;
use App\Modules\Engagement\Jobs\CheckSupportHealthJob;
use App\Modules\Engagement\Jobs\NotifyBackInStockJob;
use App\Modules\Engagement\Jobs\SendCartRemindersJob;
use App\Modules\IdentityAccess\Jobs\PurgeExpiredOtpChallenges;
use App\Modules\Inventory\Jobs\ExpireReservationsJob;
use App\Modules\Inventory\Jobs\VerifyStockLedger;
use App\Modules\Notifications\Jobs\CheckNotificationHealthJob;
use App\Modules\Notifications\Jobs\RunNotificationSweepJob;
use App\Modules\Orders\Jobs\CancelStalePendingOrdersJob;
use App\Modules\Payments\Jobs\CheckPaymentHealthJob;
use App\Modules\Payments\Jobs\ExpireUnpaidPaymentsJob;
use App\Modules\Payments\Jobs\PollRefundsJob;
use App\Modules\Payments\Jobs\ReconcilePendingPaymentsJob;
use App\Modules\Platform\Jobs\DispatchOutboxMessages;
use App\Modules\Privacy\Jobs\ExpireExportsJob;
use App\Modules\Reporting\Jobs\ExpireReportExportsJob;
use App\Modules\Reporting\Jobs\RefreshCohortsJob;
use App\Modules\Reporting\Jobs\RefreshRecentReportsJob;
use App\Modules\Reporting\Jobs\RefreshWindowReportsJob;
use App\Modules\Search\Jobs\PruneSearchQueries;
use App\Modules\Shipping\Jobs\CheckShippingHealthJob;
use App\Modules\Shipping\Jobs\PollCourierShipmentsJob;
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
Schedule::job(new NotifyBackInStockJob)->everyFiveMinutes()->name('engagement:back-in-stock')->withoutOverlapping();   // tells people who asked when an item is back
Schedule::job(new RefreshRecentReportsJob)->everyFifteenMinutes()->name('reports:refresh-recent')->withoutOverlapping();   // works today and yesterday out again for the report screens
Schedule::job(new RefreshWindowReportsJob)->dailyAt('02:45')->name('reports:refresh-window')->withoutOverlapping();   // the last 35 days, so late cancellations and refunds land on their day
Schedule::job(new RefreshCohortsJob)->dailyAt('03:15')->name('reports:refresh-cohorts')->withoutOverlapping();   // customer cohorts, worked out again from the order history
Schedule::job(new ExpireReportExportsJob)->hourly()->name('reports:expire-exports');   // deletes finished report files past their date, marks stuck ones failed
Schedule::job(new SendCartRemindersJob)->everyFifteenMinutes()->name('engagement:cart-reminders')->withoutOverlapping();   // reminds opted-in customers about a forgotten cart (does nothing unless CART_RECOVERY_ENABLED)
Schedule::job(new CheckSupportHealthJob)->hourly()->name('support:health');   // writes one error line when tickets were not answered in time
Schedule::job(new ExpireExportsJob)->dailyAt('03:20')->name('privacy:expire-exports');   // deletes data copies past their date
Schedule::job(new ReconcileListing)->everyMinute()->name('catalog:reconcile-listing')->withoutOverlapping();
Schedule::job(new PruneSearchQueries)->dailyAt('03:10')->name('search:prune-queries');
Schedule::job(new PublishDuePagesJob)->everyMinute()->name('cms:publish-due')->withoutOverlapping();   // pages scheduled to go live now
Schedule::job(new GenerateSitemaps)->dailyAt('04:00')->name('seo:sitemaps');
Schedule::job(new PruneAnalyticsEvents)->dailyAt('03:20')->name('analytics:prune');
Schedule::job(new ExpireUnpaidPaymentsJob)->everyMinute()->name('payments:expire-unpaid')->withoutOverlapping();
Schedule::job(new CancelStalePendingOrdersJob)->hourly()->name('orders:cancel-stale-pending')->withoutOverlapping();
Schedule::job(new ReconcilePendingPaymentsJob)->everyTenMinutes()->name('payments:reconcile-pending')->withoutOverlapping();
Schedule::job(new CheckPaymentHealthJob)->everyFiveMinutes()->name('payments:check-health')->withoutOverlapping();
Schedule::job(new PollCourierShipmentsJob)->everyTenMinutes()->name('shipping:poll-couriers')->withoutOverlapping();
Schedule::job(new ExpireStaleHoldsJob)->everyMinute()->name('bookings:expire-holds')->withoutOverlapping();
Schedule::job(new RunNotificationSweepJob)->everyMinute()->name('notifications:deliver-due')->withoutOverlapping();
Schedule::job(new CheckNotificationHealthJob)->hourly()->name('notifications:health')->withoutOverlapping();
Schedule::job(new RunBookingLifecycleJob)->everyFiveMinutes()->name('bookings:lifecycle')->withoutOverlapping();
Schedule::job(new CheckKeyStockJob)->everyFifteenMinutes()->name('digital:check-keys')->withoutOverlapping();
Schedule::job(new CheckShippingHealthJob)->everyFifteenMinutes()->name('shipping:check-health')->withoutOverlapping();
Schedule::job(new PollRefundsJob)->everyFiveMinutes()->name('payments:poll-refunds')->withoutOverlapping();
Schedule::job(new PurgeExpiredCarts)->dailyAt('03:40')->name('cart:purge-expired');
Schedule::job(new PublishScheduledProducts)->everyMinute()->name('catalog:publish-scheduled')->withoutOverlapping();
Schedule::job(new ExpireReservationsJob)->everyMinute()->name('inventory:expire-reservations')->withoutOverlapping();
Schedule::job(new VerifyStockLedger)->dailyAt('02:30')->name('inventory:verify-ledger');
