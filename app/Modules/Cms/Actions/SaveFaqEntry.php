<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A question and its answer in each language, as plain text. The default language is required; a language with a question but no answer (or the other way round) is refused.
 * Showing it on the help page is a separate decision: only someone who may publish can change whether an entry is shown (a new entry starts hidden).
 */
final class SaveFaqEntry
{
    public const CATEGORIES = ['orders', 'delivery', 'payment', 'returns', 'account', 'other'];

    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit, private readonly StorefrontCache $cache) {}

    /**
     * @param  array<string, array{question?: string|null, answer?: string|null}>  $translations
     * @return string the entry's public id
     *
     * @throws CmsException
     */
    public function handle(Actor $actor, ?string $publicId, string $category, int $sortOrder, ?bool $published, bool $canPublish, array $translations): string
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw CmsException::faqInvalid();
        }
        $clean = [];
        foreach ((array) config('app.supported_locales') as $locale) {
            $question = mb_substr(trim(BanglaText::input((string) ($translations[$locale]['question'] ?? ''))), 0, 200);
            $answer = mb_substr(trim(BanglaText::input((string) ($translations[$locale]['answer'] ?? ''))), 0, 4000);
            if ($question === '' && $answer === '') {
                continue;
            }
            if ($question === '' || $answer === '') {
                throw CmsException::faqHalf();
            }
            $clean[(string) $locale] = [$question, $answer];
        }
        if (! isset($clean[(string) config('app.default_locale')])) {
            throw CmsException::faqDefaultRequired();
        }

        $id = Transactions::run(function () use ($actor, $publicId, $category, $sortOrder, $published, $canPublish, $clean): int {
            $now = $this->clock->now();
            $sort = max(0, min(65535, $sortOrder));
            if ($publicId === null) {
                $id = DB::table('faq_entries')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'category' => $category, 'sort_order' => $sort, 'is_published' => $canPublish && $published === true, 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $id = (int) (DB::table('faq_entries')->where('public_id', $publicId)->value('id') ?? throw CmsException::faqNotFound());   // unique public_id
                $changes = ['category' => $category, 'sort_order' => $sort, 'updated_at' => $now];
                if ($canPublish && $published !== null) {
                    $changes['is_published'] = $published;
                }
                DB::table('faq_entries')->where('id', $id)->update($changes);
            }
            DB::table('faq_entry_translations')->where('faq_entry_id', $id)->whereNotIn('locale', array_keys($clean))->delete();
            foreach ($clean as $locale => [$question, $answer]) {
                DB::table('faq_entry_translations')->updateOrInsert(['faq_entry_id' => $id, 'locale' => $locale], ['question' => $question, 'answer' => $answer]);
            }
            $this->audit->record('cms.faq_saved', 'faq_entry', [], ['category' => $category, 'languages' => array_keys($clean)], $actor, $id);

            return $id;
        });
        $this->cache->bump();

        return (string) DB::table('faq_entries')->where('id', $id)->value('public_id');
    }

    /** @throws CmsException */
    public function delete(Actor $actor, string $publicId): void
    {
        $id = (int) (DB::table('faq_entries')->where('public_id', $publicId)->value('id') ?? throw CmsException::faqNotFound());
        DB::table('faq_entries')->where('id', $id)->delete();   // its translations go with it
        $this->audit->record('cms.faq_deleted', 'faq_entry', [], [], $actor, $id);
        $this->cache->bump();
    }
}
