<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Modules\Notifications\Exceptions\NotificationException;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Notifications\Services\TemplateRenderer;
use App\Modules\Notifications\Services\TemplateStore;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Staff change the words of a message in one language, and whether the message is on. The words may use only the variables the message declares (an unknown
 * name, or stray braces, is refused before anything is saved), must be within the channel's length, and an email subject is one line. Every change is a NEW
 * version; saving the same words again changes nothing. Who may do this (`notifications.manage`) is decided by the caller.
 */
final class SaveNotificationTemplate
{
    public function __construct(private readonly TemplateCatalog $catalog, private readonly TemplateStore $store, private readonly TemplateRenderer $renderer, private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /**
     * @return int the version now current in this language
     *
     * @throws NotificationException template_unknown | locale_unknown | words_required | too_long | subject_required | subject_invalid | variable_unknown | braces_invalid
     */
    public function handle(Actor $actor, string $code, string $locale, ?string $subject, string $body, bool $active): int
    {
        $definition = $this->catalog->get($code) ?? throw new NotificationException('template_unknown');
        if (! DB::table('languages')->where('code', $locale)->where('is_active', true)->exists()) {
            throw new NotificationException('locale_unknown');
        }

        $body = trim(str_replace("\r\n", "\n", BanglaText::input($body)));
        $subject = $definition->channel === 'email' ? trim(BanglaText::input((string) $subject)) : null;
        if ($body === '') {
            throw new NotificationException('words_required');
        }
        if (mb_strlen($body) > (int) config('notifications.max_length.'.$definition->channel)) {
            throw new NotificationException('too_long', ['max' => (int) config('notifications.max_length.'.$definition->channel)]);
        }
        if ($definition->channel === 'email') {
            if ($subject === '') {
                throw new NotificationException('subject_required');
            }
            if (mb_strlen((string) $subject) > 150 || preg_match('/[\r\n]/', (string) $subject) === 1) {
                throw new NotificationException('subject_invalid');
            }
        }

        foreach (array_filter([$body, $subject], fn ($t) => $t !== null) as $text) {
            if ($this->renderer->hasStrayBraces($text)) {
                throw new NotificationException('braces_invalid');
            }
            $unknown = array_values(array_diff($this->renderer->names($text), $definition->variables));
            if ($unknown !== []) {
                throw new NotificationException('variable_unknown', ['name' => $unknown[0]]);
            }
        }

        return Transactions::run(function () use ($actor, $code, $locale, $subject, $body, $active): int {
            $template = $this->store->ensure($code) ?? throw new NotificationException('template_unknown');
            DB::table('notification_templates')->where('id', $template->id)->lockForUpdate()->first();   // saves of the same message wait for each other, so version numbers never collide

            $current = DB::table('notification_template_versions')->where('template_id', $template->id)->where('locale', $locale)->orderByDesc('version')->first();   // unique (template_id, locale, version)
            $version = $current === null ? 0 : (int) $current->version;
            if ($current === null || (string) $current->body !== $body || $current->subject !== $subject) {
                $version++;
                DB::table('notification_template_versions')->insert(['template_id' => $template->id, 'locale' => $locale, 'version' => $version, 'subject' => $subject, 'body' => $body, 'created_by' => $actor->id, 'created_at' => $this->clock->now()]);
                $this->audit->record('notifications.template_changed', 'notification_template', [], ['code' => $code, 'locale' => $locale, 'version' => $version], $actor, $template->id);
            }
            if ((bool) $template->is_active !== $active) {
                DB::table('notification_templates')->where('id', $template->id)->update(['is_active' => $active, 'updated_at' => $this->clock->now()]);
                $this->audit->record($active ? 'notifications.template_on' : 'notifications.template_off', 'notification_template', [], ['code' => $code], $actor, $template->id);
            }

            return $version;
        });
    }
}
