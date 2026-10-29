<?php

declare(strict_types=1);

namespace App\Modules\Cart\Providers;

use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\Listeners\MergeGuestCartOnLogin;
use App\Modules\Cart\Services\CartBuilder;
use App\Modules\Platform\Support\ModuleServiceProvider;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;

class CartServiceProvider extends ModuleServiceProvider
{
    protected function module(): string
    {
        return 'Cart';
    }

    public function register(): void
    {
        $this->app->bind(CartReader::class, CartBuilder::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(Login::class, [MergeGuestCartOnLogin::class, 'handle']);
    }
}
