<?php

declare(strict_types=1);

// Browser tests only: one verified demo customer with a known password, in the throw-away e2e database.
use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\UserStatus;
use App\Modules\IdentityAccess\Models\User;

$user = new User;
$user->fill(['name' => 'Demo Customer', 'email' => 'demo.customer@example.com', 'password' => 'Demo-Pass-1234!', 'locale' => 'en']);
$user->forceFill(['account_type' => AccountType::Customer, 'status' => UserStatus::Active, 'email_verified_at' => now()])->save();
