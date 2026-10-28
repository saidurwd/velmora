<?php

declare(strict_types=1);

namespace App\Modules\Customers\Listeners;

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\IdentityAccess\Events\UserRegistered;
use App\Modules\Platform\Outbox\IdempotentConsumer;

final class CreateCustomerProfile
{
    public function __construct(private readonly CustomerProfiles $profiles, private readonly IdempotentConsumer $consumer) {}

    public function handle(UserRegistered $event): void
    {
        $this->consumer->once('customers.create_profile', $event, function () use ($event): void {
            $this->profiles->ensureForUser($event->userId, $event->name, $event->email, $event->phone);
        });
    }
}
