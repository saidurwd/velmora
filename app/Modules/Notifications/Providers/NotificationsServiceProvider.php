<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Actions\SendNotification;
use App\Modules\Notifications\Console\NotificationProbeCommand;
use App\Modules\Notifications\Contracts\MarketingConsent;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Notifications\Privacy\NotificationPersonalData;
use App\Modules\Notifications\Services\DatabaseMarketingConsent;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Notifications\Sms\LogSmsGateway;
use App\Modules\Notifications\Sms\SmsFormatTest;
use App\Modules\Notifications\Sms\SslWirelessSmsGateway;
use App\Modules\Notifications\Sms\UnconfiguredSmsGateway;
use App\Modules\Platform\Support\AdminMenu;
use App\Modules\Platform\Support\IntegrationCredentials;
use App\Modules\Platform\Support\IntegrationTests;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Privacy\Contracts\PersonalDataRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class NotificationsServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Notifications';
    }

    public function register(): void
    {
        $this->app->singleton(FakeSmsGateway::class);
        $this->app->singleton(TemplateCatalog::class);
        $this->app->bind(TemplateRegistry::class, TemplateCatalog::class);
        $this->app->bind(NotificationSender::class, SendNotification::class);
        $this->app->bind(MarketingConsent::class, DatabaseMarketingConsent::class);

        $this->app->bind(SmsGateway::class, fn ($app) => match ($driver = (string) config('services.sms.driver', 'log')) {
            'log' => new LogSmsGateway,
            'fake' => $app->make(FakeSmsGateway::class),
            'sslwireless' => new SslWirelessSmsGateway((string) config('services.sms.sslwireless.base_url'), (string) $app->make(IntegrationCredentials::class)->get('sms', 'api_token'), (string) $app->make(IntegrationCredentials::class)->get('sms', 'sid')),
            default => new UnconfiguredSmsGateway($driver),
        });
    }

    public function boot(): void
    {
        parent::boot();
        // This module's part of a customer's data export and erasure (the Privacy module asks every module for its own).
        $this->app->make(IntegrationTests::class)->add('sms', SmsFormatTest::class);
        $registry = $this->app->make(PersonalDataRegistry::class);
        $registry->addExporter(NotificationPersonalData::class);
        $registry->addEraser(NotificationPersonalData::class, 50);

        $this->commands([NotificationProbeCommand::class]);

        RateLimiter::for('notifications-actions', fn (Request $r) => Limit::perMinute(30)->by('notifications-actions|'.$r->route()?->getName().'|'.($r->user('admin')?->getAuthIdentifier() ?? $r->ip())));
        $menu = $this->app->make(AdminMenu::class);
        $menu->add('notifications::admin.menu.templates', 'admin.notifications.templates', 'notifications.view', 70);
        $menu->add('notifications::admin.menu.logs', 'admin.notifications.logs', 'notifications.view', 71);
    }
}
