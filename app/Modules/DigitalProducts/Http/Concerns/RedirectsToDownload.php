<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Http\Concerns;

use App\Modules\DigitalProducts\DTOs\DownloadLink;
use Illuminate\Http\RedirectResponse;

trait RedirectsToDownload
{
    /** Sends the browser to the short-lived signed address. Nothing about it may be cached. */
    private function toDownload(DownloadLink $link): RedirectResponse
    {
        return redirect()->away($link->url, 303)->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
