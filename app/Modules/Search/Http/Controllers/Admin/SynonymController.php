<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Controllers\Admin;

use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Search\Services\TextNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SynonymController
{
    public function index(Request $request): View
    {
        $raw = $request->query('q');
        $term = TextNormalizer::text(is_string($raw) ? $raw : '');

        return view('search::admin.synonyms', [
            'pairs' => DB::table('search_synonyms')->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('term', 'like', '%'.addcslashes($term, '%_\\').'%')->orWhere('synonym', 'like', '%'.addcslashes($term, '%_\\').'%')))
                ->orderBy('term')->orderBy('synonym')->paginate(50)->withQueryString(),
            'q' => is_string($raw) ? $raw : '',
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['term' => ['required', 'string', 'max:80'], 'synonym' => ['required', 'string', 'max:80']]);
        $term = TextNormalizer::text($data['term']);
        $synonym = TextNormalizer::text($data['synonym']);

        if ($term === '' || $synonym === '' || $term === $synonym) {
            throw ValidationException::withMessages(['term' => __('search::admin.invalid_pair')]);
        }

        // a pair works in both directions, so (a, b) and (b, a) are the same pair
        if (DB::table('search_synonyms')->where(fn ($q) => $q->where('term', $term)->where('synonym', $synonym))->orWhere(fn ($q) => $q->where('term', $synonym)->where('synonym', $term))->exists()) {
            throw ValidationException::withMessages(['term' => __('search::admin.already_exists')]);
        }

        DB::table('search_synonyms')->insert(['term' => $term, 'synonym' => $synonym, 'created_by' => $request->user('admin')?->getAuthIdentifier(), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $audit->record('search.synonym_added', 'search_synonym', after: ['term' => $term, 'synonym' => $synonym]);

        return redirect()->route('admin.search.synonyms.index')->with('status', __('search::admin.saved'));
    }

    public function destroy(int $synonym, AuditLogger $audit): RedirectResponse
    {
        $row = DB::table('search_synonyms')->where('id', $synonym)->first();
        if ($row !== null) {
            DB::table('search_synonyms')->where('id', $synonym)->delete();
            $audit->record('search.synonym_removed', 'search_synonym', ['term' => $row->term, 'synonym' => $row->synonym]);
        }

        return redirect()->route('admin.search.synonyms.index')->with('status', __('search::admin.saved'));
    }
}
