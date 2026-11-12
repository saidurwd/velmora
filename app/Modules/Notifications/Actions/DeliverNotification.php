<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\Contracts\PushGateway;
use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\DTOs\PushMessage;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Services\TemplateCatalog;
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
    public function __construct(private readonly TemplateStore $templates, private readonly TemplateRenderer $renderer, private readonly TemplateCatalog $catalog, private readonly SmsGateway $sms, private readonly PushGateway $push, private readonly Clock $clock) {}

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
            if ($row->channel === 'push') {
                return $this->deliverPush($id, $row, $template, $data);
            }
            $words = $this->templates->current((int) $template->id, (string) $row->locale);
            if ($words === null) {
                return $this->finish($id, 'dead', null, 'no_words', null);
            }
            $multiline = $this->catalog->get((string) $row->template_code)->multiline ?? [];
            $body = $this->renderer->render($words->body, $data['variables'], $multiline);
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

    /**
     * One message to each of the person's signed-in phones, each in the language that phone was registered with (else the message's own, else English). The title is the
     * shop's name; the tap target is the record's `related` (`order:<id>`). A phone the provider says is gone is forgotten. The message counts as sent when at least one phone
     * accepted it; when none did, a temporary refusal (a limit, an outage) is retried later and a final refusal is a failure like any other. A phone that failed while another
     * accepted is not retried (that could show the message twice on the phone that already has it).
     *
     * @param  array{to: string, variables: array<string, scalar|null>}  $data
     */
    private function deliverPush(int $id, \stdClass $row, \stdClass $template, array $data): string
    {
        $devices = DB::table('devices')->where('user_id', (int) $data['to'])->get(['id', 'push_token_encrypted', 'locale']);   // index user_id
        $title = mb_substr((string) ($data['variables']['shop'] ?? config('app.name')), 0, 60);
        $multiline = $this->catalog->get((string) $row->template_code)->multiline ?? [];
        $target = array_filter(['target' => $row->related === null ? null : (string) $row->related, 'template' => (string) $row->template_code]);
        $accepted = 0;
        $retry = false;
        $error = null;
        $version = null;

        foreach ($devices as $device) {
            $words = $this->templates->current((int) $template->id, (string) $device->locale) ?? $this->templates->current((int) $template->id, (string) $row->locale);
            if ($words === null) {
                $error ??= 'no_words';

                continue;
            }
            $version = $words->version;
            try {
                $token = Crypt::decryptString((string) $device->push_token_encrypted);
            } catch (Throwable) {
                continue;   // a token that can no longer be read (the app key changed) cannot be used
            }
            $result = $this->push->send($token, new PushMessage($title, $this->renderer->render($words->body, $data['variables'], $multiline), $target));
            if ($result->accepted) {
                $accepted++;
            } elseif ($result->tokenInvalid) {
                DB::table('devices')->where('id', $device->id)->delete();   // primary key: the phone is gone, never push to it again
            } else {
                $retry = $retry || $result->retryable;
                $error = $result->error ?? 'push_not_accepted';
            }
        }

        if ($accepted > 0) {
            return $this->finish($id, 'sent', $version, null, null);
        }
        if ($error === null) {   // no phones left (all gone, or none could be read): nothing to deliver, which is not a failure
            DB::table('notification_logs')->where('id', $id)->update(['status' => 'skipped', 'skip_reason' => 'no_device', 'payload' => null, 'next_attempt_at' => null, 'updated_at' => $this->clock->now()]);

            return 'skipped';
        }

        return $retry ? $this->fail($id, (int) $row->attempts, $error) : $this->finish($id, 'dead', null, $error, null);
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
