<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The cancellation rules of a service, as plain numbers, and what they mean at a given moment. The numbers live in the policy rows; a booking keeps a copy
 * of the terms from the moment it was confirmed, so editing a policy later never changes a customer's deal. All money is in minor units and whole numbers:
 * the fee is rounded DOWN, so a refund can never be more than what was paid.
 *
 * @phpstan-type Terms array{code: string, windows: list<array{hours: int, fee_bp: int}>, late_fee_bp: int, no_show_fee_bp: int, reschedule_hours: int, max_reschedules: int}
 */
final class CancellationPolicies
{
    /** @return Terms the service's own policy, else the default one, else (when the shop has none) free cancelling */
    public function termsForService(int $serviceId): array
    {
        $policyId = DB::table('service_definitions')->where('id', $serviceId)->value('cancellation_policy_id');   // primary key
        $policy = $policyId !== null
            ? DB::table('cancellation_policies')->where('id', $policyId)->first()
            : DB::table('cancellation_policies')->where('default_marker', 1)->first();   // unique default_marker
        if ($policy === null) {
            Log::warning('bookings.no_cancellation_policy', ['service_id' => $serviceId]);

            return ['code' => 'none', 'windows' => [], 'late_fee_bp' => 0, 'no_show_fee_bp' => 0, 'reschedule_hours' => 0, 'max_reschedules' => 2];
        }

        $windows = [];
        foreach (DB::table('cancellation_policy_windows')->where('policy_id', $policy->id)->orderByDesc('hours_before')->get(['hours_before', 'fee_bp']) as $w) {   // unique (policy_id, hours_before)
            $windows[] = ['hours' => (int) $w->hours_before, 'fee_bp' => (int) $w->fee_bp];
        }

        return [
            'code' => (string) $policy->code, 'windows' => $windows, 'late_fee_bp' => (int) $policy->late_fee_bp, 'no_show_fee_bp' => (int) $policy->no_show_fee_bp,
            'reschedule_hours' => (int) $policy->reschedule_hours, 'max_reschedules' => (int) $policy->max_reschedules,
        ];
    }

    /** @return Terms the terms kept on a booking, else today's rules for its service */
    public function termsOf(\stdClass $booking): array
    {
        if ($booking->policy_terms !== null) {
            /** @var Terms $terms */
            $terms = json_decode((string) $booking->policy_terms, true, 8, JSON_THROW_ON_ERROR);

            return $terms;
        }

        return $this->termsForService((int) DB::table('service_packages')->where('id', $booking->package_id)->value('service_id'));
    }

    /**
     * @param  Terms  $terms
     * @return int the fee in basis points for cancelling this many minutes before the start: the most generous window the customer still qualifies for, else the late fee
     */
    public function cancelFeeBp(array $terms, int $minutesBefore): int
    {
        foreach ($terms['windows'] as $w) {   // most hours first
            if ($minutesBefore >= $w['hours'] * 60) {
                return $w['fee_bp'];
            }
        }

        return $terms['late_fee_bp'];
    }

    /**
     * What ending a booking costs and what is then due back, for this moment: the one calculation used both to show a customer the outcome before they confirm
     * and to record it when it happens.
     *
     * @param  Terms  $terms
     * @return array{fee_bp: int, fee_minor: int, refund_due_minor: int}
     */
    public function outcome(array $terms, bool $noShow, int $minutesBefore, int $paidMinor, bool $waive = false, bool $alreadyRefunded = false): array
    {
        $feeBp = $noShow ? $terms['no_show_fee_bp'] : $this->cancelFeeBp($terms, $minutesBefore);
        $fee = $waive ? 0 : $this->feeOn($paidMinor, $feeBp);

        return ['fee_bp' => $feeBp, 'fee_minor' => $fee, 'refund_due_minor' => $alreadyRefunded ? 0 : $paidMinor - $fee];
    }

    /** The fee on an amount, rounded down to a whole minor unit. */
    public function feeOn(int $paidMinor, int $feeBp): int
    {
        return intdiv($paidMinor * $feeBp, 10000);
    }
}
