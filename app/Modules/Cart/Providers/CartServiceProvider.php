<?php

declare(strict_types=1);

namespace App\Modules\Cart\Providers;

use App\Modules\Cart\Actions\ClearCart;
use App\Modules\Cart\Contracts\AbandonedCarts;
use App\Modules\Cart\Contracts\CartFiller;
use App\Modules\Cart\Contracts\CartOwnerResolver;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\Contracts\CartWriter;
use App\Modules\Cart\Contracts\HeldCartLines;
use App\Modules\Cart\Listeners\MergeGuestCartOnLogin;
use App\Modules\Cart\Privacy\CartPersonalData;
use App\Modules\Cart\Services\CartBuilder;
use App\Modules\Cart\Services\CartLineFiller;
use App\Modules\Cart\Services\CartOwners;
use App\Modules\Cart\Services\DatabaseAbandonedCarts;
use App\Modules\Cart\Services\DatabaseHeldCartLines;
use App\Modules\Platform\Support\ModuleServiceProvider;
use App\Modules\Privacy\Contracts\PersonalDataRegistry;
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
        $this->app->bind(CartOwnerResolver::class, CartOwners::class);
        $this->app->bind(CartWriter::class, ClearCart::class);
        $this->app->bind(CartFiller::class, CartLineFiller::class);
        $this->app->bind(AbandonedCarts::class, DatabaseAbandonedCarts::class);
        $this->app->bind(HeldCartLines::class, DatabaseHeldCartLines::class);
    }

    public function boot(): void
    {
        parent::boot();
        // This module's part of a customer's data export and erasure (the Privacy module asks every module for its own).
        $registry = $this->app->make(PersonalDataRegistry::class);
        $registry->addEraser(CartPersonalData::class, 40);

        Event::listen(Login::class, [MergeGuestCartOnLogin::class, 'handle']);
    }
}
