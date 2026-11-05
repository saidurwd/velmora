<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;

/** Reads the words of a template. A message that has never been used is created from its first words in the catalog, once. */
final class TemplateStore
{
    public function __construct(private readonly TemplateCatalog $catalog, private readonly Clock $clock) {}

    /** @return object{id: int, code: string, channel: string, kind: string, is_active: bool}|null null when no module has declared this code */
    public function ensure(string $code): ?object
    {
        $definition = $this->catalog->get($code);
        if ($definition === null) {
            return null;
        }

        $now = $this->clock->now();
        DB::table('notification_templates')->insertOrIgnore(['code' => $code, 'channel' => $definition->channel, 'kind' => $definition->kind, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);   // unique code
        $template = DB::table('notification_templates')->where('code', $code)->first();
        if ($template === null) {
            return null;
        }
        foreach ($definition->defaults as $locale => [$subject, $body]) {
            DB::table('notification_template_versions')->insertOrIgnore(['template_id' => $template->id, 'locale' => $locale, 'version' => 1, 'subject' => $definition->channel === 'email' ? $subject : null, 'body' => $body, 'created_at' => $now]);
        }

        return (object) ['id' => (int) $template->id, 'code' => (string) $template->code, 'channel' => (string) $template->channel, 'kind' => (string) $template->kind, 'is_active' => (bool) $template->is_active];
    }

    /**
     * The current words in this language, else in English.
     *
     * @return object{locale: string, version: int, subject: string|null, body: string}|null
     */
    public function current(int $templateId, string $locale): ?object
    {
        foreach (array_unique([$locale, (string) config('app.fallback_locale')]) as $try) {
            $row = DB::table('notification_template_versions')->where('template_id', $templateId)->where('locale', $try)->orderByDesc('version')->first();   // unique (template_id, locale, version)
            if ($row !== null) {
                return (object) ['locale' => (string) $row->locale, 'version' => (int) $row->version, 'subject' => $row->subject, 'body' => (string) $row->body];
            }
        }

        return null;
    }
}
