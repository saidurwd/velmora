<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use Illuminate\Support\Facades\DB;

/** The ready-made answers for staff to start from. Choosing one fills in the customer's name and the order number as plain text, and nothing else: no logic, nothing evaluated. */
final class CannedReplies
{
    /** @return list<array{id: string, title: string}> the active ones, in order, titled in the language asked for (falling back to the default) */
    public function pick(string $locale): array
    {
        $default = (string) config('app.default_locale');
        $rows = DB::table('canned_replies as r')->join('canned_reply_translations as t', 't.canned_reply_id', '=', 'r.id')->where('r.is_active', true)->whereIn('t.locale', array_unique([$locale, $default]))
            ->orderBy('r.sort_order')->orderBy('r.id')->get(['r.public_id', 't.locale', 't.title']);   // index (is_active, sort_order)
        $byId = [];
        foreach ($rows as $row) {
            if (! isset($byId[$row->public_id]) || $row->locale === $locale) {
                $byId[$row->public_id] = $row->title;
            }
        }

        return array_map(fn (string $id, string $title): array => ['id' => $id, 'title' => $title], array_keys($byId), $byId);
    }

    /** The text of one reply for a customer, in their language, with the name and order filled in; null when there is no such reply. */
    public function text(string $publicId, string $locale, ?string $customerName, ?string $orderNumber): ?string
    {
        $default = (string) config('app.default_locale');
        $rows = DB::table('canned_replies as r')->join('canned_reply_translations as t', 't.canned_reply_id', '=', 'r.id')->where('r.public_id', $publicId)->where('r.is_active', true)->get(['t.locale', 't.body']);   // unique public_id
        $body = data_get($rows->firstWhere('locale', $locale), 'body') ?? data_get($rows->firstWhere('locale', $default), 'body');

        return $body === null ? null : strtr((string) $body, ['{{ customer }}' => (string) $customerName, '{{ order }}' => (string) $orderNumber]);
    }

    /** @return list<array{id: string, active: bool, sort: int, titles: array<string, string>}> everything, for the management list */
    public function all(): array
    {
        $rows = DB::table('canned_replies')->orderBy('sort_order')->orderBy('id')->get();
        $titles = DB::table('canned_reply_translations')->whereIn('canned_reply_id', $rows->pluck('id'))->get(['canned_reply_id', 'locale', 'title'])->groupBy('canned_reply_id');

        return array_values($rows->map(fn ($r) => ['id' => (string) $r->public_id, 'active' => (bool) $r->is_active, 'sort' => (int) $r->sort_order, 'titles' => ($titles->get($r->id) ?? collect())->pluck('title', 'locale')->all()])->all());
    }

    /** @return array{id: string, active: bool, sort: int, texts: array<string, array{title: string, body: string}>}|null */
    public function one(string $publicId): ?array
    {
        $row = DB::table('canned_replies')->where('public_id', $publicId)->first();   // unique public_id
        if ($row === null) {
            return null;
        }
        $texts = DB::table('canned_reply_translations')->where('canned_reply_id', $row->id)->get(['locale', 'title', 'body'])->mapWithKeys(fn ($t) => [(string) $t->locale => ['title' => (string) $t->title, 'body' => (string) $t->body]])->all();

        return ['id' => (string) $row->public_id, 'active' => (bool) $row->is_active, 'sort' => (int) $row->sort_order, 'texts' => $texts];
    }
}
