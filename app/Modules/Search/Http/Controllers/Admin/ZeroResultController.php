<?php

declare(strict_types=1);

namespace App\Modules\Search\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** What shoppers searched for and did not find: the to-do list for synonyms, product names and new stock. */
class ZeroResultController
{
    public function index(Request $request): View
    {
        $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;
        $since = now('UTC')->subDays($days);

        return view('search::admin.zero-results', [
            'days' => $days,
            'rows' => DB::table('search_queries')->where('result_count', 0)->where('created_at', '>=', $since)->groupBy('query', 'locale')
                ->orderByDesc(DB::raw('COUNT(*)'))->orderByDesc(DB::raw('MAX(created_at)'))->limit(100)
                ->get(['query', 'locale', DB::raw('COUNT(*) as searches'), DB::raw('MAX(created_at) as last_at')]),
            'total' => DB::table('search_queries')->where('created_at', '>=', $since)->count(),
        ]);
    }
}
