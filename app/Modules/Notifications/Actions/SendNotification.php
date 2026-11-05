<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Notifications\Exceptions\NotificationException;
use App\Modules\Notifications\Jobs\DeliverNotificationJob;
use App\Modules\Notifications\Services\Masker;
use App\Modules\Notifications\Services\TemplateStore;
use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a message to be delivered. Nothing is sent here: the record (with the address and values held encrypted) is the queue, and a job delivers it. The same
 * key for the same template always gives the same record, so an event announced twice sends once. A message that cannot go (no usable address; a marketing message
 * without the person's yes) is recorded as skipped with the reason, so staff can see why. A marketing message in quiet hours waits until they end.
 */
final class SendNotification implements NotificationSender
{
    public function __construct(private readonly TemplateStore $templates, private readonly Masker $masker, private readonly Clock $clock) {}

    /** @throws NotificationException template_unknown */
    public function send(string $templateCode, Recipient $to, array $variables, string $idempotencyKey, ?string $related = null): string
    {
        $template = $this->templates->ensure($templateCode) ?? throw new NotificationException('template_unknown');
        $key = mb_substr($templateCode.'|'.$idempotencyKey, 0, 120);

        $existing = DB::table('notification_logs')->where('idempotency_key', $key)->value('public_id');   // unique idempotency_key
        if ($existing !== null) {
            return (string) $existing;
        }

        $now = $this->clock->now();
        $locale = in_array($to->locale, (array) config('app.supported_locales'), true) ? $to->locale : (string) config('app.fallback_locale');
        $address = $template->channel === 'sms' ? $to->phone : $to->email;
        $masked = $address === null || $address === '' ? null : ($template->channel === 'sms' ? $this->masker->phone($address) : $this->masker->email($address));

        $skip = null;
        $nextAt = $now;
        if ($masked === null) {
            $skip = 'no_address';
        } elseif ($template->kind === 'marketing') {
            $preference = $to->userId === null ? null : DB::table('notification_preferences')->where('user_id', $to->userId)->where('channel', $template->channel)->first();   // unique (user_id, channel)
            if ($preference === null || ! $preference->marketing_opt_in) {
                $skip = 'no_consent';
            } elseif ($preference->quiet_from !== null && $preference->quiet_to !== null) {
                $nextAt = $this->afterQuietHours($now, (string) $preference->quiet_from, (string) $preference->quiet_to);
            }
        }

        $row = [
            'public_id' => strtolower((string) Str::ulid()), 'idempotency_key' => $key, 'template_code' => $templateCode, 'channel' => $template->channel, 'kind' => $template->kind, 'locale' => $locale,
            'recipient_masked' => $masked ?? '—', 'user_id' => $to->userId, 'related' => $related === null ? null : mb_substr($related, 0, 80), 'status' => $skip === null ? 'queued' : 'skipped', 'skip_reason' => $skip,
            'attempts' => 0, 'next_attempt_at' => $skip === null ? $nextAt : null,
            'payload' => $skip === null ? Crypt::encryptString(json_encode(['to' => $address, 'variables' => $variables], JSON_THROW_ON_ERROR)) : null,
            'created_at' => $now, 'updated_at' => $now,
        ];
        try {
            $id = DB::table('notification_logs')->insertGetId($row);
        } catch (UniqueConstraintViolationException) {
            return (string) DB::table('notification_logs')->where('idempotency_key', $key)->value('public_id');   // another worker recorded the same event first
        }

        if ($skip === null) {
            DB::afterCommit(fn () => DeliverNotificationJob::dispatch($id));
        }

        return $row['public_id'];
    }

    /** When the quiet hours (Dhaka time, may cross midnight) that cover this moment end; this moment itself when they do not cover it. */
    private function afterQuietHours(CarbonImmutable $now, string $from, string $to): CarbonImmutable
    {
        $zone = (string) config('bookings.timezone');
        $local = $now->setTimezone($zone);
        $time = $local->format('H:i:s');
        $from = substr($from, 0, 8);
        $to = substr($to, 0, 8);
        $inside = $from <= $to ? ($time >= $from && $time < $to) : ($time >= $from || $time < $to);
        if (! $inside) {
            return $now;
        }

        $end = $local->setTimeFromTimeString($to);
        if ($end <= $local) {
            $end = $end->addDay();   // the quiet hours run past midnight
        }

        return $end->utc();
    }
}
