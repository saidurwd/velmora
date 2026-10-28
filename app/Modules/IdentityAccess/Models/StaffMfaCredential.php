<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $secret
 * @property array<int, array{hash: string, used_at: string|null}>|null $recovery_codes
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $last_used_step
 */
class StaffMfaCredential extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret', 'recovery_codes'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'recovery_codes' => 'encrypted:array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
