<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Controllers\Web;

use App\Modules\Privacy\Actions\CancelErasureRequest;
use App\Modules\Privacy\Actions\DownloadExport;
use App\Modules\Privacy\Actions\RequestErasure;
use App\Modules\Privacy\Actions\RequestExport;
use App\Modules\Privacy\Exceptions\PrivacyException;
use App\Modules\Privacy\Http\Requests\RequestErasureRequest;
use App\Modules\Privacy\Services\PrivacyReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in customer's privacy page: ask for a copy of their data, ask for their account to be erased, follow the requests. The customer is always the one in the
 * session; a request is found by its public id AND the owner, so another customer's request is simply not found. Asking and downloading need a fresh sign-in confirmation.
 */
class PrivacyController
{
    private function userId(Request $request): int
    {
        return (int) $request->user('web')?->getAuthIdentifier();
    }

    public function show(Request $request, PrivacyReader $reader): View
    {
        $userId = $this->userId($request);
        $requests = $reader->forCustomer($userId);
        $openErasure = collect($requests)->contains(fn ($r) => $r->type === 'erasure' && in_array($r->status, ['requested', 'approved', 'blocked'], true));

        return view('privacy::account.privacy', ['requests' => $requests, 'blockers' => $openErasure ? $reader->blockersFor($userId) : []]);
    }

    public function requestExport(Request $request, RequestExport $export): RedirectResponse
    {
        try {
            $export->handle($this->userId($request), app()->getLocale());
        } catch (PrivacyException $e) {
            return redirect()->route('account.privacy')->withErrors(['privacy' => __('privacy::errors.'.$e->reason)]);
        }

        return redirect()->route('account.privacy')->with('status', __('privacy::account.export_asked'));
    }

    public function download(Request $request, DownloadExport $download, string $publicId): Response|RedirectResponse
    {
        try {
            $json = $download->handle($this->userId($request), $publicId);
        } catch (PrivacyException $e) {
            abort_if($e->reason === 'not_found', 404);

            return redirect()->route('account.privacy')->withErrors(['privacy' => __('privacy::errors.'.$e->reason)]);
        }

        return response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="my-data.json"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function requestErasure(RequestErasureRequest $request, RequestErasure $erase): RedirectResponse
    {
        try {
            $erase->handle($this->userId($request), $request->validated('reason'), app()->getLocale());
        } catch (PrivacyException $e) {
            return redirect()->route('account.privacy')->withErrors(['privacy' => __('privacy::errors.'.$e->reason)]);
        }

        return redirect()->route('account.privacy')->with('status', __('privacy::account.erasure_asked'));
    }

    public function cancelErasure(Request $request, CancelErasureRequest $cancel, string $publicId): RedirectResponse
    {
        try {
            $cancel->handle($this->userId($request), $publicId);
        } catch (PrivacyException $e) {
            abort_if($e->reason === 'not_found', 404);

            return redirect()->route('account.privacy')->withErrors(['privacy' => __('privacy::errors.'.$e->reason)]);
        }

        return redirect()->route('account.privacy')->with('status', __('privacy::account.erasure_cancelled'));
    }
}
