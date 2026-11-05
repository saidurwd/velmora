<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Privacy;

use App\Modules\Privacy\Contracts\PersonalDataEraser;
use App\Modules\Privacy\Contracts\PersonalDataExporter;
use Illuminate\Support\Facades\DB;

/**
 * A customer's bookings: the times and states go into their data export; on erasure the only thing that points at a visitor (the cart-hold key) is cleared. A booking
 * that is still to come blocks the erasure, because the shop must be able to reach the person.
 */
final class BookingPersonalData implements PersonalDataEraser, PersonalDataExporter
{
    private const OPEN = ['confirmed', 'in_progress'];

    public function section(): string
    {
        return 'bookings';
    }

    public function export(int $userId): array
    {
        return DB::table('service_bookings')->where('user_id', $userId)->whereNotIn('status', ['held'])->orderBy('id')->get()   // index (user_id, starts_at)
            ->map(fn ($b) => ['reference' => $b->public_id, 'status' => $b->status, 'starts_at' => $b->starts_at, 'ends_at' => $b->ends_at, 'booked_at' => $b->created_at, 'completed_at' => $b->completed_at ?? null])->all();
    }

    public function blockers(int $userId): array
    {
        return DB::table('service_bookings')->where('user_id', $userId)->whereIn('status', self::OPEN)->exists() ? ['upcoming'] : [];
    }

    public function erase(int $userId): void
    {
        DB::table('service_bookings')->where('user_id', $userId)->whereNotNull('holder_key')->update(['holder_key' => null]);
    }
}
