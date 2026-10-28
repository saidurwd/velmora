<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent provider restricted to one account type, so a customer session can never resolve a staff user
 * (and vice versa) even if ids collide or a cookie is replayed across guards.
 */
final class AccountTypeUserProvider extends EloquentUserProvider
{
    public function __construct(Hasher $hasher, string $model, private readonly string $accountType)
    {
        parent::__construct($hasher, $model);
    }

    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    public function newModelQuery($model = null)
    {
        /** @var Builder<Model> $query */
        $query = parent::newModelQuery($model);

        return $query->where('account_type', $this->accountType);
    }
}
