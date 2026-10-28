<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Notifications\Sms\LogSmsGateway;
use App\Modules\Notifications\Sms\UnconfiguredSmsGateway;
use App\Modules\Platform\Support\ModuleServiceProvider;

class NotificationsServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Notifications';
    }

    public function register(): void
    {
        $this->app->singleton(FakeSmsGateway::class);

        $this->app->bind(SmsGateway::class, fn ($app) => match ($driver = (string) config('services.sms.driver', 'log')) {
            'log' => new LogSmsGateway,
            'fake' => $app->make(FakeSmsGateway::class),
            default => new UnconfiguredSmsGateway($driver),
        });
    }
}
