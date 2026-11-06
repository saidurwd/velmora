<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use Illuminate\Support\Facades\DB;

/** The help centre's questions. The public page shows only published ones, in the customer's language (falling back to the default); a search looks at the question and the answer. */
final class FaqReader
{
    /**
     * @return array<string, list<array{question: string, answer: string}>> category => questions, in order
     */
    public function published(string $locale, string $search = ''): array
    {
        $default = (string) config('app.default_locale');
        $query = DB::table('faq_entries as e')->join('faq_entry_translations as t', 't.faq_entry_id', '=', 'e.id')->where('e.is_published', true)->whereIn('t.locale', array_unique([$locale, $default]))
            ->orderBy('e.category')->orderBy('e.sort_order')->orderBy('e.id');   // index (is_published, category, sort_order)
        $rows = $query->get(['e.id', 'e.category', 't.locale', 't.question', 't.answer']);

        $byEntry = [];
        foreach ($rows as $row) {
            if (! isset($byEntry[$row->id]) || $row->locale === $locale) {
                $byEntry[$row->id] = $row;   // the customer's language wins over the default
            }
        }
        $needle = mb_strtolower(trim($search));
        $out = [];
        foreach ($byEntry as $row) {
            if ($needle !== '' && ! str_contains(mb_strtolower($row->question.' '.$row->answer), $needle)) {
                continue;
            }
            $out[(string) $row->category][] = ['question' => (string) $row->question, 'answer' => (string) $row->answer];
        }

        return $out;
    }

    /** @return list<array{id: string, category: string, published: bool, sort: int, question: string}> everything, for the management list */
    public function all(string $locale): array
    {
        $default = (string) config('app.default_locale');
        $rows = DB::table('faq_entries')->orderBy('category')->orderBy('sort_order')->orderBy('id')->get();
        $titles = DB::table('faq_entry_translations')->whereIn('faq_entry_id', $rows->pluck('id'))->get(['faq_entry_id', 'locale', 'question'])->groupBy('faq_entry_id')
            ->map(fn ($group) => $group->pluck('question', 'locale')->all());

        return array_values($rows->map(fn ($e) => [
            'id' => (string) $e->public_id, 'category' => (string) $e->category, 'published' => (bool) $e->is_published, 'sort' => (int) $e->sort_order,
            'question' => (string) (($titles->get($e->id)[$locale] ?? null) ?? ($titles->get($e->id)[$default] ?? '')),
        ])->all());
    }

    /** @return array{id: string, category: string, published: bool, sort: int, texts: array<string, array{question: string, answer: string}>}|null */
    public function one(string $publicId): ?array
    {
        $e = DB::table('faq_entries')->where('public_id', $publicId)->first();   // unique public_id
        if ($e === null) {
            return null;
        }
        $texts = DB::table('faq_entry_translations')->where('faq_entry_id', $e->id)->get(['locale', 'question', 'answer'])->mapWithKeys(fn ($t) => [(string) $t->locale => ['question' => (string) $t->question, 'answer' => (string) $t->answer]])->all();

        return ['id' => (string) $e->public_id, 'category' => (string) $e->category, 'published' => (bool) $e->is_published, 'sort' => (int) $e->sort_order, 'texts' => $texts];
    }
}
