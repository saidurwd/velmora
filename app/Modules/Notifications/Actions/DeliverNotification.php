<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Services\TemplateRenderer;
use App\Modules\Notifications\Services\TemplateStore;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\PhoneNumber;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one recorded message. The record is CLAIMED first with one conditional update (it must be queued or failed and due, or a claim a worker abandoned),
 * so any number of workers or repeated runs deliver it once. The words are read and filled in now (the current version, in the person's language, else English).
 * On success the address and values are erased from the record. On failure the next try waits longer each time; after the last wait the message is dead and
 * staff can see it and retry it by hand. A failure carries only a code or a plain label, never the address, the values or the text.
 */
final class DeliverNotification
{
    public function __construct(private readonly TemplateStore $templates, private readonly TemplateRenderer $renderer, private readonly SmsGateway $sms, private readonly Clock $clock) {}

    /** @return string sent | failed | dead | skipped | busy */
    public function handle(int $id): string
    {
        $now = $this->clock->now();
        $stale = $now->subMinutes((int) config('notifications.claim_minutes'));
        $claimed = DB::table('notification_logs')->where('id', $id)
            ->where(fn ($q) => $q->where(fn ($d) => $d->whereIn('status', ['queued', 'failed'])->where('next_attempt_at', '<=', $now))->orWhere(fn ($s) => $s->where('status', 'sending')->where('claimed_at', '<', $stale)))
            ->update(['status' => 'sending', 'claimed_at' => $now, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);   // primary key
        if ($claimed !== 1) {
            return 'busy';   // not due yet, already sent, or another worker has it
        }

        $row = DB::table('notification_logs')->where('id', $id)->first();
        $template = $row === null ? null : DB::table('notification_templates')->where('code', $row->template_code)->first();   // unique code
        if ($row === null || $template === null || $row->payload === null) {
            return $this->finish($id, 'dead', null, 'payload_missing', null);
        }
        if (! $template->is_active) {
            DB::table('notification_logs')->where('id', $id)->update(['status' => 'skipped', 'skip_reason' => 'template_off', 'payload' => null, 'updated_at' => $now]);

            return 'skipped';
        }

        try {
            /** @var array{to: string, variables: array<string, scalar|null>} $data */
            $data = json_decode(Crypt::decryptString((string) $row->payload), true, 8, JSON_THROW_ON_ERROR);
            $words = $this->templates->current((int) $template->id, (string) $row->locale);
            if ($words === null) {
                return $this->finish($id, 'dead', null, 'no_words', null);
            }
            $body = $this->renderer->render($words->body, $data['variables']);
            $reference = null;

            if ($row->channel === 'sms') {
                $result = $this->sms->send(PhoneNumber::parse($data['to']), $body, ['purpose' => (string) $row->template_code]);
                if (! $result->accepted) {
                    return $this->fail($id, (int) $row->attempts, (string) ($result->error ?? 'sms_not_accepted'));
                }
                $reference = $result->providerReference;
            } else {
                Mail::to($data['to'])->send(new NotificationMail($this->renderer->singleLine($this->renderer->render((string) $words->subject, $data['variables'])), $body, (string) $row->locale));
            }
        } catch (Throwable $e) {
            return $this->fail($id, (int) $row->attempts, $e::class);
        }

        return $this->finish($id, 'sent', $words->version, null, $reference);
    }

    private function fail(int $id, int $attempts, string $error): string
    {
        $waits = (array) config('notifications.backoff_minutes');
        $error = mb_substr($error, 0, 120);
        if ($attempts > count($waits)) {
            return $this->finish($id, 'dead', null, $error, null);   // the address and values stay (encrypted) so staff can retry, until the retention period ends
        }

        DB::table('notification_logs')->where('id', $id)->update(['status' => 'failed', 'last_error' => $error, 'next_attempt_at' => $this->clock->now()->addMinutes((int) $waits[$attempts - 1]), 'updated_at' => $this->clock->now()]);

        return 'failed';
    }

    private function finish(int $id, string $status, ?int $version, ?string $error, ?string $reference): string
    {
        $now = $this->clock->now();
        DB::table('notification_logs')->where('id', $id)->update($status === 'sent'
            ? ['status' => 'sent', 'sent_at' => $now, 'template_version' => $version, 'provider_ref' => $reference === null ? null : mb_substr($reference, 0, 64), 'last_error' => null, 'payload' => null, 'next_attempt_at' => null, 'updated_at' => $now]
            : ['status' => $status, 'last_error' => $error, 'next_attempt_at' => null, 'updated_at' => $now]);

        return $status;
    }
}
