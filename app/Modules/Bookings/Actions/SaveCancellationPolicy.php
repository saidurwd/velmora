<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Makes or changes a cancellation policy: what is kept when a customer cancels, moves or misses a booking, and the words they read. Fees are in basis points
 * (10000 = the whole price). The rules refuse nonsense: every fee is from 0 to 100%; windows are whole hours, one per number of hours; and closer to the start
 * never costs LESS (each window's fee is at least the fee of any window further away, the late fee is at least the last window's, and a no-show costs at least
 * the late fee). English words are required, others optional. Exactly one policy is the default. Bookings already confirmed keep the terms they were made under.
 */
final class SaveCancellationPolicy
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{hours: int, fee_bp: int}>  $windows
     * @param  array<string, array{title?: string|null, text?: string|null}>  $words  by language
     * @return string the policy's public id
     *
     * @throws BookingException policy_unknown | fee_invalid | windows_invalid | fees_not_rising | move_rules_invalid | words_required | default_required
     */
    public function handle(Actor $actor, ?string $policyPublicId, int $lateFeeBp, int $noShowFeeBp, int $rescheduleHours, int $maxReschedules, bool $isDefault, array $windows, array $words): string
    {
        foreach ([$lateFeeBp, $noShowFeeBp] as $fee) {
            if ($fee < 0 || $fee > 10000) {
                throw new BookingException('fee_invalid');
            }
        }
        if ($rescheduleHours < 0 || $rescheduleHours > 8760 || $maxReschedules < 0 || $maxReschedules > 20) {
            throw new BookingException('move_rules_invalid');
        }

        $seen = [];
        foreach ($windows as $w) {
            if ($w['hours'] < 1 || $w['hours'] > 8760 || isset($seen[$w['hours']])) {
                throw new BookingException('windows_invalid');
            }
            if ($w['fee_bp'] < 0 || $w['fee_bp'] > 10000) {
                throw new BookingException('fee_invalid');
            }
            $seen[$w['hours']] = true;
        }
        usort($windows, fn (array $a, array $b): int => $b['hours'] <=> $a['hours']);   // furthest from the start first
        $previous = 0;
        foreach ($windows as $w) {
            if ($w['fee_bp'] < $previous) {
                throw new BookingException('fees_not_rising');
            }
            $previous = $w['fee_bp'];
        }
        if ($lateFeeBp < $previous || $noShowFeeBp < $lateFeeBp) {
            throw new BookingException('fees_not_rising');
        }

        $clean = [];
        foreach ($words as $locale => $w) {
            $title = mb_substr(trim(BanglaText::input((string) ($w['title'] ?? ''))), 0, 120);
            $text = trim(BanglaText::input((string) ($w['text'] ?? '')));
            if ($title !== '' && $text !== '') {
                $clean[(string) $locale] = [$title, mb_substr($text, 0, 4000)];
            }
        }
        if (! isset($clean['en'])) {
            throw new BookingException('words_required');
        }

        return Transactions::run(function () use ($actor, $policyPublicId, $lateFeeBp, $noShowFeeBp, $rescheduleHours, $maxReschedules, $isDefault, $windows, $clean): string {
            $now = now();
            $existing = $policyPublicId === null ? null : (DB::table('cancellation_policies')->where('public_id', $policyPublicId)->lockForUpdate()->first() ?? throw new BookingException('policy_unknown'));
            if ($existing !== null && $existing->is_default && ! $isDefault) {
                throw new BookingException('default_required');   // make another policy the default instead
            }
            $hasDefault = DB::table('cancellation_policies')->where('is_default', true)->exists();
            $makeDefault = $isDefault || (! $hasDefault);   // the very first policy is the default

            if ($makeDefault) {
                DB::table('cancellation_policies')->where('is_default', true)->when($existing !== null, fn ($q) => $q->where('id', '!=', $existing?->id))->update(['is_default' => false, 'updated_at' => $now]);
            }

            $fields = ['late_fee_bp' => $lateFeeBp, 'no_show_fee_bp' => $noShowFeeBp, 'reschedule_hours' => $rescheduleHours, 'max_reschedules' => $maxReschedules, 'is_default' => $makeDefault, 'updated_at' => $now];
            if ($existing === null) {
                $id = DB::table('cancellation_policies')->insertGetId($fields + ['public_id' => strtolower((string) Str::ulid()), 'code' => $this->code($clean['en'][0]), 'created_by' => $actor->id, 'created_at' => $now]);
                $public = (string) DB::table('cancellation_policies')->where('id', $id)->value('public_id');
            } else {
                $id = (int) $existing->id;
                DB::table('cancellation_policies')->where('id', $id)->update($fields);
                $public = (string) $existing->public_id;
            }

            DB::table('cancellation_policy_windows')->where('policy_id', $id)->delete();
            foreach ($windows as $w) {
                DB::table('cancellation_policy_windows')->insert(['policy_id' => $id, 'hours_before' => $w['hours'], 'fee_bp' => $w['fee_bp']]);
            }
            DB::table('cancellation_policy_translations')->where('policy_id', $id)->delete();
            foreach ($clean as $locale => [$title, $text]) {
                DB::table('cancellation_policy_translations')->insert(['policy_id' => $id, 'locale' => $locale, 'title' => $title, 'text' => $text]);
            }

            $this->audit->record($existing === null ? 'bookings.policy_created' : 'bookings.policy_changed', 'cancellation_policy', [], [
                'late_fee_bp' => $lateFeeBp, 'no_show_fee_bp' => $noShowFeeBp, 'reschedule_hours' => $rescheduleHours, 'max_reschedules' => $maxReschedules, 'default' => $makeDefault, 'windows' => $windows,
            ], $actor, $id);

            return $public;
        });
    }

    private function code(string $title): string
    {
        $base = mb_substr(Str::slug($title) ?: 'policy', 0, 30);
        $code = $base;
        for ($i = 2; DB::table('cancellation_policies')->where('code', $code)->exists(); $i++) {
            $code = $base.'-'.$i;
        }

        return $code;
    }
}
