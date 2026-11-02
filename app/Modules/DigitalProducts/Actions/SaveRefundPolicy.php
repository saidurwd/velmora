<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Actions;

use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Models\RefundPolicy;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staff write what a refund means for digital products: the rule, the days (for a time limit), whether a fully refunded order loses its downloads and keys,
 * and the words customers read, per language (English is required, Bangla falls back to it). Making a policy the default un-defaults the old one in the same
 * transaction. Who may do this (`digital.manage`) is decided by the caller.
 */
final class SaveRefundPolicy
{
    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /**
     * @param  array<string, array<string, string>>  $words  locale => title and text
     *
     * @throws DigitalException
     */
    public function handle(Actor $actor, ?RefundPolicy $policy, string $rule, ?int $days, bool $revoke, bool $makeDefault, array $words): RefundPolicy
    {
        if (! in_array($rule, RefundPolicy::RULES, true)) {
            throw new DigitalException('policy_rule_invalid');
        }
        if ($rule === 'within_days' && ($days === null || $days < 1 || $days > 3650)) {
            throw new DigitalException('policy_days_invalid');
        }
        $clean = [];
        foreach ($words as $locale => $w) {
            $title = mb_substr(trim(BanglaText::input((string) ($w['title'] ?? ''))), 0, 120);
            $text = mb_substr(trim(BanglaText::input((string) ($w['text'] ?? ''))), 0, 2000);
            if ($title !== '' && $text !== '') {
                $clean[$locale] = ['title' => $title, 'text' => $text];
            } elseif ($title !== '' || $text !== '') {
                throw new DigitalException('policy_words_incomplete', ['locale' => $locale]);   // a title without text, or text without a title
            }
        }
        if (! isset($clean[(string) config('app.default_locale')])) {
            throw new DigitalException('policy_words_required');
        }

        return Transactions::run(function () use ($actor, $policy, $rule, $days, $revoke, $makeDefault, $clean): RefundPolicy {
            $before = $policy === null ? [] : $policy->only(['rule', 'days', 'revoke_on_full_refund', 'is_default']);
            $wasDefault = $policy !== null && $policy->is_default;
            if ($makeDefault) {
                DB::table('refund_policies')->where('is_default', true)->update(['is_default' => false, 'updated_at' => $this->clock->now()]);
            }
            $fields = ['rule' => $rule, 'days' => $rule === 'within_days' ? $days : null, 'revoke_on_full_refund' => $revoke, 'is_default' => $makeDefault || $wasDefault];
            if ($policy === null) {
                $policy = RefundPolicy::query()->create($fields + ['code' => 'p-'.Str::lower(Str::random(8)), 'created_by' => $actor->id]);
            } else {
                $policy->forceFill($fields)->save();
            }
            DB::table('refund_policy_translations')->where('policy_id', $policy->id)->whereNotIn('locale', array_keys($clean))->delete();
            foreach ($clean as $locale => $w) {
                DB::table('refund_policy_translations')->updateOrInsert(['policy_id' => $policy->id, 'locale' => $locale], ['title' => $w['title'], 'text' => $w['text']]);
            }
            $this->audit->record($before === [] ? 'digital.refund_policy_created' : 'digital.refund_policy_changed', $policy, $before, $policy->only(['rule', 'days', 'revoke_on_full_refund', 'is_default']) + ['locales' => array_keys($clean)], $actor);

            return $policy->load('translations');
        });
    }
}
