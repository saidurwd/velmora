<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Search;

use App\Modules\Platform\Contracts\SearchProvider;
use App\Modules\Platform\DTOs\SearchHit;
use Illuminate\Support\Facades\DB;

/** Support tickets by their reference (the code a customer quotes). The customer's words and name are never shown here. */
final class TicketSearch implements SearchProvider
{
    public function kind(): string
    {
        return 'engagement::search.tickets';
    }

    public function permissions(): array
    {
        return ['support.view'];
    }

    public function search(string $term, int $limit, bool $seePii): array
    {
        $ref = strtoupper(trim($term));
        if (preg_match('/^[A-Z0-9-]{3,9}$/', $ref) !== 1) {
            return [];
        }

        return array_values(DB::table('support_tickets')->where('reference', 'like', addcslashes($ref, '%_\\').'%')->orderByDesc('id')->limit($limit)->get(['public_id', 'reference', 'category', 'status'])   // unique index reference: a prefix range
            ->map(fn ($t): SearchHit => new SearchHit((string) $t->reference, __('engagement::search.ticket_detail', ['category' => __('engagement::support.category.'.$t->category), 'status' => (string) $t->status]), route('admin.support.show', (string) $t->public_id)))->all());
    }
}
