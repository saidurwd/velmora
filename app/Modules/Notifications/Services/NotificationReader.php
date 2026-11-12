<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** What the staff screens show: the messages and their words, and the delivery record. It only reads. */
final class NotificationReader
{
    public const STATUSES = ['queued', 'sending', 'sent', 'failed', 'dead', 'skipped'];

    public function __construct(private readonly TemplateCatalog $catalog, private readonly TemplateStore $store) {}

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        $out = [];
        foreach ($this->catalog->all() as $code => $definition) {
            $template = $this->store->ensure($code);
            $out[] = ['code' => $code, 'channel' => $definition->channel, 'kind' => $definition->kind, 'active' => (bool) ($template->is_active ?? true), 'variables' => $definition->variables];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function template(string $code): ?array
    {
        $definition = $this->catalog->get($code);
        $template = $this->store->ensure($code);
        if ($definition === null || $template === null) {
            return null;
        }

        $locales = array_values(array_map('strval', DB::table('languages')->where('is_active', true)->orderByDesc('is_default')->pluck('code')->all()));
        $words = [];
        $history = [];
        foreach ($locales as $locale) {
            $own = DB::table('notification_template_versions')->where('template_id', $template->id)->where('locale', $locale)->orderByDesc('version')->first();   // unique (template_id, locale, version)
            $words[$locale] = $own === null ? null : (object) ['version' => (int) $own->version, 'subject' => $own->subject, 'body' => (string) $own->body];
        }
        foreach (DB::table('notification_template_versions as v')->leftJoin('users as u', 'u.id', '=', 'v.created_by')->where('v.template_id', $template->id)->orderByDesc('v.created_at')->orderByDesc('v.version')->limit(60)
            ->get(['v.locale', 'v.version', 'v.subject', 'v.body', 'v.created_at', 'u.name as by']) as $h) {
            $history[] = (array) $h;
        }

        return ['definition' => $definition, 'active' => $template->is_active, 'locales' => $locales, 'words' => $words, 'history' => $history];
    }

    /**
     * @param  array{status?: string, template?: string, channel?: string, from?: string, to?: string}  $f
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function logs(array $f): LengthAwarePaginator
    {
        $q = DB::table('notification_logs');
        if (in_array($f['status'] ?? '', self::STATUSES, true)) {
            $q->where('status', $f['status']);
        }
        if (($f['template'] ?? '') !== '') {
            $q->where('template_code', $f['template']);
        }
        if (in_array($f['channel'] ?? '', ['email', 'sms', 'push'], true)) {
            $q->where('channel', $f['channel']);
        }
        $from = (string) ($f['from'] ?? '');
        $to = (string) ($f['to'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $q->where('created_at', '>=', $from.' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $q->where('created_at', '<=', $to.' 23:59:59');
        }

        return $q->select(['public_id', 'template_code', 'channel', 'kind', 'locale', 'recipient_masked', 'related', 'status', 'skip_reason', 'attempts', 'next_attempt_at', 'sent_at', 'last_error', 'created_at', DB::raw('payload IS NOT NULL AS has_payload')])
            ->orderByDesc('id')->paginate(50);   // the kept address itself never leaves the database
    }

    /** @return array{dead: int, failing: int, queued: int} */
    public function health(): array
    {
        return [
            'dead' => DB::table('notification_logs')->where('status', 'dead')->count(),
            'failing' => DB::table('notification_logs')->where('status', 'failed')->count(),
            'queued' => DB::table('notification_logs')->whereIn('status', ['queued', 'sending'])->count(),
        ];
    }
}
