<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Http\Controllers\Web;

use App\Modules\DigitalProducts\Actions\DownloadDigitalFile;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Http\Concerns\RedirectsToDownload;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\DigitalProducts\Services\LibraryReader;
use App\Modules\Orders\Contracts\CustomerOrders;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** "My library" for a signed-in customer: the downloads of their own orders. Another customer's download is simply "not found" (and remembered). */
class LibraryController
{
    use RedirectsToDownload;

    public function index(Request $request, CustomerOrders $orders, LibraryReader $reader): View
    {
        $rows = $reader->forOrders($orders->orderIdsOwnedBy((int) $request->user('web')?->getAuthIdentifier()), app()->getLocale());
        $rows = array_map(fn (array $r): array => $r + ['action' => route('digital.download', $r['id'])], $rows);

        return view('digital-products::library.index', ['rows' => $rows])->with('guest', false);
    }

    public function download(Request $request, DownloadDigitalFile $download, string $entitlement): RedirectResponse
    {
        $found = Entitlement::query()->where('public_id', $entitlement)->firstOrFail();
        $user = $request->user('web');
        abort_if($user === null, 403);

        if (! Gate::forUser($user)->allows('download', $found)) {
            $download->logNotOwner($found, (int) $user->getAuthIdentifier(), $request->ip(), $request->userAgent());

            abort(404);
        }

        try {
            return $this->toDownload($download->handle($found, (int) $user->getAuthIdentifier(), $request->ip(), $request->userAgent()));
        } catch (DigitalException $e) {
            return back()->withErrors(['download' => __('digital-products::library.errors.'.$e->reason)]);
        }
    }
}
