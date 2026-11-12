<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Remembers a phone's push token for one person and one app sign-in (ADR P11-2). The token is stored encrypted; its SHA-256 finds it again.
 * The same token registered by someone else MOVES the row: the phone changed hands, and the previous owner must never be pushed to again.
 * Two apps registering at once cannot make two rows (the unique hash decides; the loser updates).
 */
final class RegisterDevice
{
    public function __construct(private readonly Clock $clock) {}

    /** @return array{id: string, created: bool} */
    public function handle(int $userId, string $family, string $pushToken, string $platform, ?string $appVersion, string $locale): array
    {
        $hash = hash('sha256', $pushToken);
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $fields = ['user_id' => $userId, 'family_id' => $family, 'platform' => $platform, 'app_version' => $appVersion, 'locale' => $locale, 'last_seen_at' => $now];

        $existing = DB::table('devices')->where('push_token_hash', $hash)->first();
        if ($existing !== null) {
            DB::table('devices')->where('id', $existing->id)->update($fields);

            return ['id' => (string) $existing->public_id, 'created' => false];
        }

        $publicId = strtolower((string) Str::ulid());
        try {
            DB::table('devices')->insert($fields + ['public_id' => $publicId, 'push_token_hash' => $hash, 'push_token_encrypted' => Crypt::encryptString($pushToken), 'created_at' => $now]);
        } catch (UniqueConstraintViolationException) { // the same token arrived twice at once: the first one won, this one updates it
            $row = DB::table('devices')->where('push_token_hash', $hash)->firstOrFail();
            DB::table('devices')->where('id', $row->id)->update($fields);

            return ['id' => (string) $row->public_id, 'created' => false];
        }

        return ['id' => $publicId, 'created' => true];
    }
}
