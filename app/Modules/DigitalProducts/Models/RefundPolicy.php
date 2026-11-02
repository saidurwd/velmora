<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Models;

use App\Modules\Platform\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $rule
 * @property int|null $days
 * @property bool $revoke_on_full_refund
 * @property bool $is_default
 */
class RefundPolicy extends Model
{
    use HasPublicId;

    public const RULES = ['non_refundable', 'until_first_use', 'within_days'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['revoke_on_full_refund' => 'boolean', 'is_default' => 'boolean'];
    }

    /** @return HasMany<RefundPolicyTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(RefundPolicyTranslation::class, 'policy_id');
    }

    /**
     * The title and text in this language, else the shop's default language, else nothing.
     *
     * @return array{title: string, text: string}|null
     */
    public function words(string $locale): ?array
    {
        $by = $this->translations->keyBy('locale');
        $row = $by[$locale] ?? $by[(string) config('app.default_locale')] ?? null;

        return $row === null ? null : ['title' => $row->title, 'text' => $row->text];
    }
}
