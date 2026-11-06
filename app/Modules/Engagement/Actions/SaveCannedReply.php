<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Actions;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A ready-made answer in each language, as plain text. The default language is required; a language with a title but no text (or the other way round) is refused. Who may (`support.manage`) is decided by the route. */
final class SaveCannedReply
{
    public const BODY_MAX = 2000;

    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, array{title?: string|null, body?: string|null}>  $translations  locale => title and text
     * @return string the reply's public id
     *
     * @throws EngagementException not_found | default_required | half_translation
     */
    public function handle(Actor $actor, ?string $publicId, bool $active, int $sortOrder, array $translations): string
    {
        $clean = [];
        foreach ((array) config('app.supported_locales') as $locale) {
            $title = mb_substr(trim(BanglaText::input((string) ($translations[$locale]['title'] ?? ''))), 0, 80);
            $body = mb_substr(trim(BanglaText::input((string) ($translations[$locale]['body'] ?? ''))), 0, self::BODY_MAX);
            if ($title === '' && $body === '') {
                continue;
            }
            if ($title === '' || $body === '') {
                throw new EngagementException('half_translation');
            }
            $clean[(string) $locale] = [$title, $body];
        }
        if (! isset($clean[(string) config('app.default_locale')])) {
            throw new EngagementException('default_required');
        }

        return Transactions::run(function () use ($actor, $publicId, $active, $sortOrder, $clean): string {
            $now = $this->clock->now();
            if ($publicId === null) {
                $publicId = strtolower((string) Str::ulid());
                $id = DB::table('canned_replies')->insertGetId(['public_id' => $publicId, 'is_active' => $active, 'sort_order' => max(0, min(65535, $sortOrder)), 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $id = (int) (DB::table('canned_replies')->where('public_id', $publicId)->value('id') ?? throw new EngagementException('not_found'));   // unique public_id
                DB::table('canned_replies')->where('id', $id)->update(['is_active' => $active, 'sort_order' => max(0, min(65535, $sortOrder)), 'updated_at' => $now]);
            }
            DB::table('canned_reply_translations')->where('canned_reply_id', $id)->whereNotIn('locale', array_keys($clean))->delete();
            foreach ($clean as $locale => [$title, $body]) {
                DB::table('canned_reply_translations')->updateOrInsert(['canned_reply_id' => $id, 'locale' => $locale], ['title' => $title, 'body' => $body]);
            }
            $this->audit->record('support.canned_saved', 'canned_reply', [], ['active' => $active, 'languages' => array_keys($clean)], $actor, $id);

            return $publicId;
        });
    }

    /** @throws EngagementException not_found */
    public function delete(Actor $actor, string $publicId): void
    {
        $id = (int) (DB::table('canned_replies')->where('public_id', $publicId)->value('id') ?? throw new EngagementException('not_found'));
        DB::table('canned_replies')->where('id', $id)->delete();   // its translations go with it
        $this->audit->record('support.canned_deleted', 'canned_reply', [], [], $actor, $id);
    }
}
